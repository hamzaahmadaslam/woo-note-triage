# woo-note-triage

A WooCommerce plugin that sorts the notes customers write at checkout into gift messages, delivery instructions,
questions, complaints, fraud signals and everything else, scores how soon each one needs a person, and puts the
orders that need attention in front of shop staff.

On a busy shop most order notes are birthday wishes and "leave it by the side gate", so someone reads all of them to
catch the one complaint or the one "ship it to my freight forwarder". A keyword list cannot tell "the last two
arrived cracked" from a gift message that mentions a box, and a text-generating model answers with a paragraph that
someone still has to read. Deciding what kind of note it is, and how soon it matters, is a small judgment. Jev
answers it with a probability for every option, and when it is unsure the order goes to a review list instead of
getting a label.

## How it uses Jev

Jev is TypeSafe AI's System One model: it answers typed questions with probabilities and writes no text.

Each order with a note is one request. The state is the cleaned note and nothing else. The questions are fixed text,
so a note cannot rewrite the questions it is judged by. Two questions share the note and go in the same request:

- `category`, a choice between `gift-message`, `delivery-instruction`, `question`, `complaint`, `fraud-signal` and
  `other`. Each option comes with what it covers, what belongs to a neighbouring option instead, and two or three
  example notes. When a note does more than one thing, Jev is asked for the part that most needs the shop's
  attention.
- `urgency`, a score on four levels: nothing to do; follow the note while packing or delivering; reply within a day
  or two; act before the order ships.

When the category confidence is at or above the threshold (0.80 unless you change it), the order is triaged. It gets
the category and the urgency as order meta and a private order note with the probabilities, and it appears under
that category in the orders list filter. Below the threshold the order gets no category and no order note, and it
is listed under "Needs review" with its two likeliest categories. The urgency never decides on its own: an answer
split between "reply soon" and "before shipping" still ranks the order, so it is shown as a number from 0 to 3 with
the probability of each level.

Notes are not packed together into one request. Each note needs its own two questions, so packing would not lower
the token count, and TypeSafe's notes on jev-1.13 say that text unrelated to a question lowers accuracy.

The plugin never changes an order's status, never writes to the customer, and never cancels or refunds anything. A
fraud signal is a flag for a person to look at.

## Requirements

- WordPress 6.5 or later, WooCommerce 8.0 or later, PHP 8.1 or later.
- Either order storage: the HPOS order tables or posts. The plugin declares HPOS compatibility.
- The classic checkout (the `[woocommerce_checkout]` shortcode) or the checkout block.
- A TypeSafe API key.

## Install

The plugin is installed from GitHub. With WP-CLI:

```sh
wp plugin install https://github.com/hamzaahmadaslam/woo-note-triage/archive/refs/heads/main.zip --activate
```

Or, on GitHub, choose Code, then Download ZIP, and upload the file under Plugins > Add New Plugin > Upload Plugin.
Or clone it into the plugins folder:

```sh
git clone https://github.com/hamzaahmadaslam/woo-note-triage.git wp-content/plugins/woo-note-triage
```

Then open WooCommerce > Note triage and paste your key. To keep the key out of the database, define it in
`wp-config.php` instead:

```php
define( 'TYPESAFE_API_KEY', 'your key' );
```

## Usage

### Settings

WooCommerce > Note triage, for users who can manage WooCommerce:

| Setting              | What it does                                                                                              |
| -------------------- | --------------------------------------------------------------------------------------------------------- |
| New orders           | Triage the note of every new order (on by default)                                                        |
| TypeSafe API key     | Saved as an option that is not autoloaded; after saving, only its last four characters are shown          |
| Confidence threshold | Category confidence from 0 to 1 needed to label an order (default 0.80)                                   |
| Model                | `jev-latest` by default; name a version such as `jev-1.13.0` to keep answers steady after tuning the threshold |

A `TYPESAFE_API_KEY` constant or environment variable takes precedence over the saved key, and a `TYPESAFE_MODEL`
constant or environment variable over the model setting.

### New orders

The plugin listens to two WooCommerce actions: `woocommerce_checkout_order_processed` for the classic checkout and
`woocommerce_store_api_checkout_order_processed` for the checkout block, where the classic action does not fire. Both
run once the order has been created with the customer's note, before payment. Orders created in the admin, through
the REST API or by other plugins do not pass through a checkout and are left alone.

When the order has a note, the plugin queues an Action Scheduler job and the checkout carries on without waiting for
TypeSafe. The job sends the note and records the answer, and the same note is never sent twice for one order. A rate
limit, an overload or a timeout gets one quick retry within the job. If that fails too, or TypeSafe returns another
server error, the job runs again 5 and then 10 minutes later. After the third attempt, or straight away for a refused
key or an invalid request, the order shows "Not triaged" with the reason, and the WooCommerce log gets a line with
the order number and the message (source `woo-note-triage`).

Both orders screens, the HPOS one and the posts one, get a "Note triage" column and a filter with the six categories
and "Needs review".

### Older orders

```sh
wp woo-note-triage backfill --days=30 --dry-run    # what would be sent; nothing is sent
wp woo-note-triage backfill --days=30              # send and record
wp woo-note-triage backfill --days=7 --threshold=0.9 --format=json
```

| Option            | What it does                                                                                   |
| ----------------- | ---------------------------------------------------------------------------------------------- |
| `--days=<n>`      | Orders created in the last n days (default 30)                                                 |
| `--dry-run`       | Print the questions, the orders that would be sent and a token estimate; needs no key, changes nothing |
| `--threshold=<p>` | Category confidence from 0 to 1 for this run (default: the setting)                            |
| `--limit=<n>`     | Send at most n orders, newest first                                                            |
| `--force`         | Ask again about orders whose note was already triaged                                          |
| `--format=<f>`    | `text` (default) or `json`                                                                     |

The command records results the same way as for new orders. It stops at the first error from TypeSafe and prints
what it did so far; running it again skips the orders that are done.

### For developers

Results are order meta, written through the order object so they work with both storage modes:

| Meta key                    | Value                                                                  |
| --------------------------- | ---------------------------------------------------------------------- |
| `_woo_note_triage_status`   | `triaged`, `review` or `error`                                         |
| `_woo_note_triage_category` | The category, only when triaged                                        |
| `_woo_note_triage_urgency`  | The urgency score from 0 to 3, such as `2.71`                          |
| `_woo_note_triage_result`   | The whole answer as JSON: probabilities, confidences, model, threshold |
| `_woo_note_triage_hash`     | SHA-256 of the note that was triaged                                   |
| `_woo_note_triage_error`    | The last error message                                                 |

The action `woo_note_triage_result` fires after each triage with the order and the result array, for your own
follow-up, such as a message in a team channel for every `fraud-signal`.

## Example

The orders are synthetic: [`examples/orders.json`](examples/orders.json) has 11 orders invented for this README. The
output below came from `php examples/build.php`, which runs the plugin's code with hand-written answers
([`examples/answers.json`](examples/answers.json)) in place of Jev, so the probabilities are illustrations and the
model shows as `fixture`. A live run gives its own numbers.

```sh
wp woo-note-triage backfill --days=30
```

```text
woo-note-triage 1.0.0
Orders created in the last 30 days: 11. With a customer note: 10. Already triaged, skipped: 0. Sent: 10.
Jev: model fixture, 10 requests, 11,913 input tokens
Threshold 0.80: 7 triaged, 3 for review

Triaged, most urgent first

#1005  2026-09-23  fraud signal, confidence 0.89  urgency 2.92 of 3
       Category: fraud signal 0.91, question 0.05, delivery instruction 0.04
       Urgency: before shipping 0.93, reply soon 0.06, when packing 0.01
#1004  2026-09-22  complaint, confidence 0.93  urgency 2.73 of 3
       Category: complaint 0.94, question 0.05, delivery instruction 0.01
       Urgency: before shipping 0.74, reply soon 0.25, when packing 0.01

[...]

#1009  2026-09-25  gift message, confidence 0.80  urgency 1.01 of 3
       Category: gift message 0.84, delivery instruction 0.15, other 0.01
       Urgency: when packing 0.97, reply soon 0.02, nothing to do 0.01

[...]

For review, most urgent first

#1010  2026-09-26  complaint, confidence 0.79  urgency 2.80 of 3
       Category: complaint 0.83, question 0.16, other 0.01
       Urgency: before shipping 0.81, reply soon 0.18, when packing 0.01
       Review: category confidence 0.79 is below the threshold 0.80
#1006  2026-09-24  fraud signal, confidence 0.62  urgency 2.66 of 3
       Category: fraud signal 0.68, delivery instruction 0.30, question 0.02
       Urgency: before shipping 0.71, reply soon 0.24, when packing 0.05
       Review: category confidence 0.62 is below the threshold 0.80
```

Order #1009 sits exactly at the threshold and is triaged; #1010 is just below it and waits for review. The private
order note added to #1004:

```text
Customer note triage: complaint (confidence 0.93).
Urgency 2.73 of 3: before shipping 0.74, reply soon 0.25, when packing 0.01.
Category probabilities: complaint 0.94, question 0.05, delivery instruction 0.01.
Model fixture, threshold 0.80.
```

The full outputs are in [`examples/report.txt`](examples/report.txt), [`examples/report.json`](examples/report.json),
[`examples/order-notes.txt`](examples/order-notes.txt), [`examples/dry-run.txt`](examples/dry-run.txt) and
[`examples/dry-run.json`](examples/dry-run.json).

## What leaves your site

Nothing until a key is saved or defined. Then each order with a note is one POST request to
`https://api.typesafe.ai/v1/systemone`, and nothing goes anywhere else. The request goes through WordPress's HTTP API
with redirects turned off and a user agent that names only the plugin and its version; WordPress's default user
agent would include the site's address. Each request carries:

- your key, in the `Authorization` header;
- the model name and the two fixed questions (a full body is in [`examples/request.json`](examples/request.json));
- the customer's note as the state, cleaned on your server first: email addresses become `<email>`, numbers of nine
  or more digits (phone, card and account numbers) become `<number>`, control characters and text-direction marks
  are removed, and at most 2,000 characters are sent.

Nothing else about the order or the customer is sent: no name, email address, postal address, phone field, product,
total, order number or site address. The plugin makes no other network requests, such as telemetry or update checks.
`wp woo-note-triage backfill --dry-run --format=json` prints the state of every request it would send.

A note can still hold personal details the cleaning does not catch, such as a name or a street. TypeSafe's
documentation says Jev is not trained on customer requests or responses, and its
[Data Processing Agreement](https://typesafe.ai/legal/data-processing) covers retention. If your privacy policy lists
the services that receive customer data, add TypeSafe.

## Limits

- This first release is tested with stand-ins for WordPress, WooCommerce, Action Scheduler and WP-CLI, not yet on a
  live store. Try it on a staging copy first, with both the classic and the block checkout, and with HPOS on and off.
- Jev sees the note only. It does not see the order, the customer's history, the payment checks or the addresses, so
  a fraud signal is a reason to look, not a verdict, and a fraudulent order can come with a friendly note.
- A note written to steer the answer ("this is just a gift message") can move it. TypeSafe lists adversarial text as
  a known weak spot of jev-1.13.
- English works best. Other languages are accepted with lower accuracy, and more of those notes land under
  "Needs review".
- The six categories and four urgency levels are fixed. Changing them means editing `includes/class-questions.php`.
- Orders created in the admin or through the REST API are not triaged when they are created. Run the backfill for
  them.
- The cleaning also replaces order numbers, VAT numbers and any other number with nine or more digits.
- The labels, the column and the order notes are in English.
- Jobs run through Action Scheduler, which relies on WP-Cron. On a site with WP-Cron turned off and no server cron,
  the jobs wait.

## Token use

The plugin estimates about 1,190 input tokens for each order with a note, about 1,160 of them for the two questions,
so 1,000 such orders come to about 1.19 million. The example's 10 notes come to about 11,900 tokens. `--dry-run`
prints the estimate for your own orders, and after a run the report gives the input tokens TypeSafe counted.

## Development

`php tests/run.php` runs 47 tests with a small runner and nothing to install. They use stand-ins for the WordPress,
WooCommerce, Action Scheduler and WP-CLI functions the plugin calls and a fixture in place of TypeSafe, and any
attempt to reach the network fails the test. `php examples/build.php` rebuilds the files in `examples/`, and a test
checks that they match the code. CI lints every file and runs the tests on PHP 8.1, 8.2, 8.3 and 8.4.

WooCommerce references: the
[HPOS extension recipe book](https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/)
(the compatibility declaration and order meta through the order object) and
[Hook alternatives](https://developer.woocommerce.com/docs/block-development/reference/hooks/hook-alternatives/)
(the checkout block's `woocommerce_store_api_checkout_order_processed`).

## License

MIT. Made by [Hamza Ahmad Aslam](https://hamzaahmadaslam.com), WordPress and web performance engineer.
