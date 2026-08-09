import { dateTime, money } from './format';

/**
 * Print an invoice as a counter receipt.
 *
 * Not the same job as the PDF. The PDF is a file you keep or email; this goes
 * straight to the printer next to the till while the customer is standing
 * there. It is laid out for an 80mm roll and falls back sanely on A4, and it
 * prints from a hidden iframe rather than a popup — a popup is the thing
 * browsers block, and being blocked at the counter is worse than useless.
 */
export function printInvoice(invoice, cafeName) {
    const frame = document.createElement('iframe');

    frame.setAttribute('aria-hidden', 'true');
    frame.setAttribute('title', 'Receipt');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';

    document.body.appendChild(frame);

    const doc = frame.contentDocument;
    doc.open();
    doc.write(receiptHtml(invoice, cafeName));
    doc.close();

    // The document has to have laid out before the print dialog measures it,
    // and an empty first page is the classic symptom of not waiting.
    const run = () => {
        frame.contentWindow.focus();
        frame.contentWindow.print();
        // Long enough for the dialog to take its snapshot on every engine.
        setTimeout(() => frame.remove(), 2000);
    };

    if (doc.readyState === 'complete') {
        run();
    } else {
        frame.onload = run;
    }
}

/** Text going into HTML always goes through here — a customer typed the name. */
function esc(value) {
    return String(value ?? '').replace(
        /[&<>"']/g,
        (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
    );
}

function row(label, value, { bold = false, muted = false } = {}) {
    return `<tr class="${bold ? 'b' : ''}${muted ? ' muted' : ''}">
        <td>${esc(label)}</td>
        <td class="r">${esc(value)}</td>
    </tr>`;
}

const METHODS = {
    cash: 'Cash',
    phone_payment: 'Phone payment',
    wallet: 'Wallet',
};

function receiptHtml(invoice, cafeName) {
    const items = invoice.items ?? [];
    const minutes = invoice.duration_minutes ?? 0;

    const lines = [
        row(
            `Play · ${minutes} min` +
                (invoice.controllers ? ` · ${invoice.controllers} pad${invoice.controllers === 1 ? '' : 's'}` : ''),
            money(invoice.session_amount),
        ),
        ...items.map((item) =>
            row(`${item.description} × ${item.quantity}`, money(item.amount)),
        ),
        Number(invoice.discount_amount) > 0
            ? row(invoice.discount_reason || 'Discount', `-${money(invoice.discount_amount)}`)
            : '',
    ]
        .filter(Boolean)
        .join('');

    const paid =
        invoice.payment_status === 'paid'
            ? `PAID${invoice.payment_method ? ` · ${METHODS[invoice.payment_method] ?? invoice.payment_method}` : ''}`
            : 'UNPAID';

    return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Invoice ${esc(invoice.id)}</title>
<style>
  @page { size: 80mm auto; margin: 4mm; }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font: 12px/1.45 ui-monospace, "SFMono-Regular", "Menlo", "Consolas", monospace;
    color: #000;
    background: #fff;
    width: 72mm;
  }
  h1 { margin: 0; font-size: 15px; letter-spacing: .04em; text-transform: uppercase; }
  .c { text-align: center; }
  .r { text-align: right; white-space: nowrap; }
  .muted { color: #444; }
  .b { font-weight: 700; }
  .rule { border-top: 1px dashed #000; margin: 6px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 1px 0; vertical-align: top; }
  .total td { font-size: 15px; font-weight: 700; padding-top: 4px; }
  .meta { margin: 2px 0 0; font-size: 11px; }
  .stamp {
    margin-top: 6px; text-align: center; font-weight: 700; letter-spacing: .08em;
    border: 1px solid #000; padding: 3px 0;
  }
  .foot { margin-top: 8px; text-align: center; font-size: 11px; }
</style>
</head>
<body>
  <div class="c">
    <h1>${esc(cafeName || 'CafeTrack')}</h1>
    <p class="meta">Invoice #${esc(invoice.id)}</p>
    <p class="meta">${esc(dateTime(invoice.created_at))}</p>
  </div>

  <div class="rule"></div>

  <table>
    ${row('Station', invoice.station_name || '—')}
    ${row('Player', invoice.customer_name || 'Walk-in')}
    ${invoice.hourly_rate ? row('Rate', `${money(invoice.hourly_rate)}/hr`) : ''}
  </table>

  <div class="rule"></div>

  <table>${lines}</table>

  <div class="rule"></div>

  <table>
    <tr class="total"><td>TOTAL</td><td class="r">${esc(money(invoice.total_amount))}</td></tr>
  </table>

  <div class="stamp">${esc(paid)}</div>

  <p class="foot">Thank you — see you again!</p>
</body>
</html>`;
}
