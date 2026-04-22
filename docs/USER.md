# Using the Donation Form

This document describes the donation experience for donors. It assumes the site is already online; for installation and configuration, see [ADMIN.md](ADMIN.md).

## The donation page

When a donor visits the donation page they see:

- A short explanation of what the foundation does and a note that it is a 501(c)(3) non-profit.
- Three example impact statements (for $25, $100, and $500) describing what each amount can fund. These statements are illustrative; they are not automatically verified against the foundation's audited financials.
- A form to choose an amount, enter their details, and optionally cover processing fees.
- A tax-deductibility note and a list of other ways to give (mail, employer matching, planned giving).

## Choosing an amount

There are five preset buttons (**$25**, **$50**, **$100**, **$250**, **$500**) and an **Other** option. The form opens with **$100** pre-selected and the amount field already filled in, so a donor who wants to give $100 can continue straight to checkout. Selecting a different preset fills in its value; selecting **Other** lets the donor type any amount between **$10.00** and **$10,000.00** (inclusive). The amount field accepts whole dollars or two decimal places (for example, `75` or `75.50`). Other formats &mdash; scientific notation, commas, currency symbols, or negative numbers &mdash; are rejected at submission with a clear error.

If a donor types a custom amount that matches a preset (for example, `100`), the preset button switches to reflect the selection automatically. If they type a custom value like `100.50`, **Other** is selected.

## Covering processing fees

Below the amount field, donors can choose **"Yes, I'll cover the fee"** or **"No thanks"**. When **Yes** is selected, the application computes a new total so that after Stripe's standard US card fee (2.9% plus $0.30), the foundation receives the donor's intended amount in full.

A live preview below the button shows exactly what the donor's card will be charged, for example:

> Your card will be charged $103.19 so we receive $100.00 after fees.

This preview is informational; the final amount shown on Stripe's checkout page is authoritative, and the two always agree.

## Proceeding to Stripe

When the donor clicks **Continue to secure checkout**, the application validates the amount and contact details, creates a Stripe Checkout session, and redirects the browser to Stripe's hosted payment page. **Card details are entered on Stripe, not on this site.**

On the Stripe page the donor may see:

- A card-entry form.
- **Link** (Stripe's one-click payment method), if the donor has an account.
- **Apple Pay** or **Google Pay** on supported devices and browsers.
- **ACH Direct Debit** for US bank transfers, if enabled on the foundation's Stripe account.

Which methods appear is controlled by the foundation's Stripe dashboard; the application requests Stripe to surface all enabled methods automatically.

## After payment

### Success page

Once Stripe confirms the donor's payment, the browser returns to a **thank-you page** that shows one of three messages:

1. **"Thank you for your support"** &mdash; card and wallet payments. The donation completed successfully and Stripe has sent the donor's receipt.
2. **"Your donation is being processed"** &mdash; ACH and other bank-backed methods. These take one to several business days to clear. No further action is required; Stripe will email a receipt once the payment confirms.
3. **"We're confirming your donation"** &mdash; the thank-you page could not retrieve the current payment status in time. This is rare; the donor's payment still proceeds independently, and the receipt email is the authoritative confirmation.

All three states tell the donor to check their email for a receipt, note that the foundation is a 501(c)(3), and invite them to keep the receipt for tax records.

### Cancel page

If the donor clicks the back button or closes the Stripe page without completing payment, they are returned to the donation form with a banner:

> Payment canceled. Your card was not charged &mdash; you can try again any time.

No charge is made.

### Receipts

The donor's receipt email is sent directly by **Stripe**, not by this application. It contains the donation amount, date, and the last four digits of the card (or the payment method description for wallets/ACH). The foundation is copied on every successful donation via a separate staff notification.

If a donor does not receive a receipt, it usually means the payment did not complete, or the receipt email was caught in a spam filter. The footer on the thank-you page tells the donor to check spam first and, if still missing, to contact `info@ndasafoundation.org`.

## Error messages

If validation rejects something the donor typed, the form is re-displayed with a short message at the top and all the fields still filled in. The donor can correct the one thing that failed and submit again without starting over.

If something outside the donor's control prevents a checkout from being created, a dedicated error page is shown with a reassuring note:

> Your card has not been charged. Payments only go through on Stripe's secure checkout page after you confirm the amount.

Specific situations the application recognises:

- **Input problem (400 / 422)** &mdash; the donor's name, email, or amount did not pass validation, or the page's session expired before submission. The form re-renders with the donor's values still in place and a short message at the top. A single retry almost always succeeds.
- **Too many requests (429)** &mdash; more than five donation attempts were made from the donor's network in a one-minute window. Waiting briefly and trying again almost always resolves it.
- **Payment processor unavailable (502)** &mdash; the application briefly could not reach Stripe. No charge is made.
- **Page not found (404)** &mdash; the URL is incorrect; a link back to the donation form is provided.
- **Generic problem** &mdash; something unexpected happened on our end. The donor is encouraged to refresh, try again, or contact us directly.

Each error page includes an email link to `info@ndasafoundation.org` so the donor can reach a human directly.

## Tax-deductibility

The NDASA Foundation is a registered 501(c)(3). Contributions are generally tax-deductible to the fullest extent allowed by law; the emailed receipt serves as the donor's record. The on-page copy reminds donors to consult a tax advisor for situation-specific guidance.

## Other ways to give

For donors who prefer not to pay online, the page lists three alternatives: mailing a cheque, employer matching programmes, and planned giving or bequests. Each links or refers to the foundation's main contact address.

## Privacy and security

- Card details are entered on Stripe, never on this site.
- The donor's name and email are stored alongside the Stripe payment record for receipt and reconciliation purposes only.
- No donor tracking cookies are set. Standard session cookies exist purely to support the form's CSRF protection.
