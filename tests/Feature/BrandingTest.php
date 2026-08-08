<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\PlatformSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Platform-wide branding — the login background and the logo. */
class BrandingTest extends TestCase
{
    private Cafe $cafe;

    private AdminUser $owner;

    private AdminUser $admin;

    private AdminUser $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->cafe = $this->makeCafe();
        $this->owner = $this->makeUser(null, 'superadmin', 'owner@example.com');
        $this->admin = $this->makeUser($this->cafe, 'admin', 'admin@example.com');
        $this->staff = $this->makeUser($this->cafe, 'staff', 'staff@example.com');
    }

    private function png(string $name = 'bg.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 1600, 900);
    }

    /**
     * An upload whose MIME type is sniffed from its bytes, not its name.
     *
     * UploadedFile::fake() derives the type from the filename, which makes it
     * useless for testing whether we are fooled by a filename.
     */
    private function realUpload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'brand');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    /* -------------------------------------------------------------- reading */

    public function test_branding_is_readable_without_an_account(): void
    {
        // The login page renders before anyone has a token, so this must not 401.
        $this->getJson('/api/branding')
            ->assertOk()
            ->assertExactJson(['login_background_url' => null, 'logo_url' => null]);
    }

    public function test_an_unset_image_is_404_not_a_broken_stream(): void
    {
        $this->get('/api/branding/logo')->assertNotFound();
        $this->get('/api/branding/login-background')->assertNotFound();
    }

    public function test_an_unknown_asset_name_is_404(): void
    {
        $this->get('/api/branding/favicon')->assertNotFound();
    }

    /* ------------------------------------------------------------ uploading */

    public function test_the_owner_can_upload_a_login_background(): void
    {
        $response = $this->postJson(
            '/api/branding/login-background',
            ['image' => $this->png()],
            $this->headersFor($this->owner),
        )->assertOk();

        $this->assertNotNull($response->json('login_background_url'));
        $this->assertNull($response->json('logo_url'));

        $path = PlatformSetting::where('key', 'login_background')->value('value');
        Storage::disk('local')->assertExists($path);
    }

    public function test_the_owner_can_upload_a_logo(): void
    {
        $this->postJson('/api/branding/logo', ['image' => $this->png()], $this->headersFor($this->owner))
            ->assertOk()
            ->assertJsonPath('login_background_url', null);

        $this->assertNotNull(PlatformSetting::where('key', 'logo')->value('value'));
    }

    public function test_an_uploaded_image_is_then_public(): void
    {
        $this->postJson(
            '/api/branding/login-background',
            ['image' => $this->png()],
            $this->headersFor($this->owner),
        );

        $url = $this->getJson('/api/branding')->json('login_background_url');

        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_url_changes_when_the_image_does(): void
    {
        // Otherwise the owner uploads a new background, sees the old one come
        // back out of cache, and reasonably concludes it did not work.
        $this->postJson('/api/branding/logo', ['image' => $this->png('a.png')], $this->headersFor($this->owner));
        $first = $this->getJson('/api/branding')->json('logo_url');

        $this->postJson('/api/branding/logo', ['image' => $this->png('b.png')], $this->headersFor($this->owner));
        $second = $this->getJson('/api/branding')->json('logo_url');

        $this->assertNotSame($first, $second);
    }

    public function test_replacing_an_image_deletes_the_one_it_replaced(): void
    {
        $this->postJson('/api/branding/logo', ['image' => $this->png('a.png')], $this->headersFor($this->owner));
        $old = PlatformSetting::where('key', 'logo')->value('value');

        $this->postJson('/api/branding/logo', ['image' => $this->png('b.png')], $this->headersFor($this->owner));

        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists(PlatformSetting::where('key', 'logo')->value('value'));
    }

    /* -------------------------------------------------------------- removing */

    public function test_the_owner_can_remove_an_image(): void
    {
        $this->postJson('/api/branding/logo', ['image' => $this->png()], $this->headersFor($this->owner));
        $path = PlatformSetting::where('key', 'logo')->value('value');

        $this->deleteJson('/api/branding/logo', [], $this->headersFor($this->owner))
            ->assertOk()
            ->assertJsonPath('logo_url', null);

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, PlatformSetting::where('key', 'logo')->count());
    }

    public function test_removing_an_image_that_was_never_set_is_harmless(): void
    {
        $this->deleteJson('/api/branding/logo', [], $this->headersFor($this->owner))
            ->assertOk()
            ->assertJsonPath('logo_url', null);
    }

    public function test_removing_one_image_leaves_the_other_alone(): void
    {
        $this->postJson('/api/branding/logo', ['image' => $this->png()], $this->headersFor($this->owner));
        $this->postJson('/api/branding/login-background', ['image' => $this->png()], $this->headersFor($this->owner));

        $this->deleteJson('/api/branding/logo', [], $this->headersFor($this->owner))->assertOk();

        $this->assertNull($this->getJson('/api/branding')->json('logo_url'));
        $this->assertNotNull($this->getJson('/api/branding')->json('login_background_url'));
    }

    /* ------------------------------------------------------------ permission */

    public function test_a_cafe_admin_cannot_restyle_the_platform(): void
    {
        $this->postJson(
            '/api/branding/logo',
            ['image' => $this->png()],
            $this->headersFor($this->admin),
        )->assertForbidden();

        $this->deleteJson('/api/branding/logo', [], $this->headersFor($this->admin))->assertForbidden();
    }

    public function test_staff_cannot_change_branding(): void
    {
        $this->postJson(
            '/api/branding/logo',
            ['image' => $this->png()],
            $this->headersFor($this->staff),
        )->assertForbidden();
    }

    public function test_a_guest_cannot_change_branding(): void
    {
        $this->postJson('/api/branding/logo', ['image' => $this->png()])->assertUnauthorized();
        $this->deleteJson('/api/branding/logo')->assertUnauthorized();
    }

    /* ------------------------------------------------------------ validation */

    public function test_an_svg_is_refused(): void
    {
        // An SVG is a document that can carry <script>, and we serve branding
        // back from our own origin. It is not an image for this purpose.
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->postJson('/api/branding/logo', ['image' => $svg], $this->headersFor($this->owner))
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_a_script_wearing_an_image_extension_is_refused(): void
    {
        // Built by hand rather than with UploadedFile::fake(), which takes the
        // MIME type from the filename it is given — exactly the thing under
        // test. Passing null makes Symfony sniff the bytes, as a real upload does.
        $this->postJson(
            '/api/branding/logo',
            ['image' => $this->realUpload('logo.png', '<?php echo shell_exec($_GET["c"]); ?>')],
            $this->headersFor($this->owner),
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_the_stored_extension_follows_the_bytes_not_the_filename(): void
    {
        // A PNG that claims to be a JPEG. What ends up on disk — and what we
        // later hand back as a Content-Type — has to follow the content.
        $png = (function (): string {
            $image = imagecreatetruecolor(8, 8);
            ob_start();
            imagepng($image);

            return (string) ob_get_clean();
        })();

        $this->postJson(
            '/api/branding/logo',
            ['image' => $this->realUpload('logo.jpg', $png)],
            $this->headersFor($this->owner),
        )->assertOk();

        $this->assertStringEndsWith('.png', PlatformSetting::where('key', 'logo')->value('value'));
    }

    public function test_an_oversized_image_is_refused(): void
    {
        $huge = UploadedFile::fake()->image('bg.png')->size(6 * 1024);

        $this->postJson('/api/branding/logo', ['image' => $huge], $this->headersFor($this->owner))
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_the_image_field_is_required(): void
    {
        $this->postJson('/api/branding/logo', [], $this->headersFor($this->owner))
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_the_upload_records_who_changed_it(): void
    {
        $this->postJson('/api/branding/logo', ['image' => $this->png()], $this->headersFor($this->owner));

        $row = PlatformSetting::find('logo');

        $this->assertSame('owner@example.com', $row->updated_by_email);
        $this->assertNotNull($row->updated_at);
    }
}
