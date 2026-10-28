# Newspack Modal Checkout Google Analytics Tracking

The Newspack Modal Checkout uses <a href="https://github.com/Automattic/newspack-plugin/blob/trunk/includes/data-events/README.md">Newspack Data Events</a> to help track information for Google Analytics 4.

## Actions

Each checkout will include the following actions:

| `action`                   | When it's used                                                                                                     |
| ---------------------------| ------------------------------------------------------------------------------------------------------------------ |
| `opened`                   | When the modal checkout is opened, either from the Donate Block, Checkout Button Block, or Variation picker screen |
| `opened_variations`        | When a variation picker is opened from a Checkout button block                                                     |
| `loaded`                   | When the modal finishes loading                                                                                    |
| `continue`                 | When the 'Continue' button is clicked                                                                              |
| `back`                     | When the 'Back' button is clicked                                                                                  |
| `dismissed`                | When the modal is closed before completion                                                                         |
| `form_submission`          | When a submission attempt is made                                                                                  |
| `form_submission_success`  | When a submission attempt is completed (back-end event)

## Action types

Each checkout will include the following action types:

| `action_type`     | When it's used                                                    |
| ----------------- | ----------------------------------------------------------------- |
| `checkout_button` | When the modal checkout is triggered by the Checkout Button block |
| `donation`        | When the modal checkout is triggered by the Donation block        |

## What's captured

Each checkout will capture the following information on most actions.

| What's captured    | When it's captured                                                                                                  |
| ------------------ | ------------------------------------------------------------------------------------------------------------------- |
| `amount`           | For each action, except when opening a variation picker since the price isn't known                                 |
| `currency`         | For each action                                                                                                     |
| `is_variable`      | Only the initial `open_variations` action when opening a variation picker                                           |
| `product_id`       | For each action                                                                                                     |
| `product_type`***  | For each action                                                                                                     |
| `recurrence`       | For each action, except when opening a variation picker for a subscription product since the recurrence isn't known |
| `referer`          | For each action                                                                                                     |
| `variation_id`     | For each action after picking a product variation when purchasing a variable product                                |


*** Note: The product types have yet to be finalized, but currently are: `product`, `subscription`, `membership`, and `donation`.