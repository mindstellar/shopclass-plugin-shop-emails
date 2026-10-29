# Changelog

## 0.1.1

### Changed
- Listens to core's typed `OrderPlaced` event on a core that has it.

## 0.1.0

### New
- Newsletter sign-up for a theme's form (Aisle's home page form), with double opt-in, a honeypot and an hourly limit per visitor.
- Confirm and unsubscribe links that carry no address; unconfirmed sign-ups are removed after 3 days.
- Newsletter subscribers screen under Plugins: counts, search by the start of an address, delete, CSV export of confirmed addresses.
- One reminder e-mail for a signed-in shopper's cart left unchanged for a day, through the hourly cron and the job queue. Its button opens the cart (`#cart` on the home page) unless the owner sets a link.
- Settings for the opt-in, both mails' subject and text, the limit, the reminder delay and the reminders per run.
- Sign-ups and reminder marks in the personal-data export, and removed with the account.
