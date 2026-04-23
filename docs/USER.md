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

Above the amount picker, three **impact cards** describe what specific gift sizes fund. Clicking a card selects the matching preset and scrolls the frequency block into view.

## Choosing a frequency

Below the amount, donors pick **One-time**, **Monthly**, or **Yearly**. One-time is the default. Monthly and yearly donations create a Stripe subscription at checkout; the first charge happens immediately, and renewals continue automatically on the same day each period. Donors can cancel at any time from the link on the thank-you page (see **After payment**). The donate button and the live total preview update to reflect the chosen frequency, e.g. **"Give $100.00 monthly →"**.

## Covering processing fees

Below the amount field, donors see a short explanation that card fees take a small amount out of each donation, with the exact dollar figure shown live next to the choice:

> Card fees take about $3.30 out of each donation. If you'd like, add it so 100% of your gift funds our programs.

The two choices are **"Yes, I'll add $3.30"** (the amount updates as the donation amount changes) and **"No thanks"**. When **Yes** is selected, the application computes a new total so that after Stripe's standard US card fee, the foundation receives the donor's intended amount in full.

A live preview below the button shows exactly what the donor's card will be charged, for example:

> Your card will be charged $103.30 so we receive $100.00 after fees.

This preview is informational; the final amount shown on Stripe's checkout page is authoritative, and the two always agree.

## The donate button

The submit button reflects the donor's current amount in real time &mdash; for example, **"Donate $100.00 securely →"**. When no valid amount is selected the button reads **"Choose an amount above"** so the next action is always clear. After the donor clicks the button, it changes to **"Redirecting to secure checkout…"** while the payment session is created, so there is no ambiguity about whether the click registered.

## Dedicating the donation (optional)

A short text field below the contact details lets donors dedicate the gift "in memory of" or "in honor of" someone (up to 200 characters). The dedication is shared with NDASA staff on the internal notification email but is **not** printed on the donor's Stripe receipt.

## Newsletter opt-in

A checkbox below the email field, pre-checked, lets the donor choose to receive occasional updates about the impact of their gift. Unchecking it before donating records the preference; the donation still completes normally.

## Proceeding to Stripe

When the donor clicks the donate button, the application validates the amount and contact details, creates a Stripe Checkout session, and redirects the browser to Stripe's hosted payment page. **Card details are entered on Stripe, not on this site.**

On the Stripe page the donor enters a card. The application currently requests **card** as the payment method — Apple Pay, Google Pay, Link, and ACH are not enabled on this account. If new payment methods are enabled in the Stripe dashboard in the future, they will appear at checkout automatically.

## After payment

### Success page

Once Stripe confirms the donor's payment, the browser returns to a **thank-you page** that shows one of three messages:

1. **"Thank you for your support"** &mdash; card payments. The donation completed successfully and Stripe has sent the donor's receipt.
2. **"Your donation is being processed"** &mdash; bank-backed methods such as ACH, if later enabled. These take one to several business days to clear. No further action is required; Stripe will email a receipt once the payment confirms.
3. **"We're confirming your donation"** &mdash; the thank-you page could not retrieve the current payment status in time. This is rare; the donor's payment still proceeds independently, and the receipt email is the authoritative confirmation.

All three states tell the donor to check their email for a receipt, note that the foundation is a 501(c)(3), and invite them to keep the receipt for tax records.

For **recurring donations**, the thank-you page also shows a "Manage or cancel this donation" link that opens Stripe's Customer Portal, where the donor can update their card, change the amount on their next renewal, or cancel the subscription without contacting the foundation. A generic "check with your HR department for employer matching" note and share buttons (X, Facebook, LinkedIn, email) round out the page.

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
