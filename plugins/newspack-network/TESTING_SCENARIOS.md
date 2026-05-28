# Testing

Here are a few scenarios to make sure the plugin is working as expected.

## Basic communication

* Follow the "Setting up" steps on [the readme file](README.md) to configure the Hub and at least two Nodes.
* Make sure RAS is enabled in all sites
* As a reader, register to one of the Nodes
* Wait a couple of minutes for the async actions to run
* Visit the Event Log panel on the Hub and confirm the user registration event on the Node is there
* Still on the Hub, check your Users list and confirm the user email registered on the Node was created as a Network Reader in the Hub
* On the second Node, check that the reader was also created (it might take another couple of minutes)

See the troubleshooting section in the [developer docs](DEV_NOTES.md) to learn how to follow the events.

## Specific events

`reader_registered` is covered in the instructions above

### `newspack_node_order_changed` and `newspack_node_subscription_changed`:

* In one of the Nodes, make a Woocommerce purchase.
* Check that the event show up in the Hub's Event Log
* In the Hub, go to the Subscriptions or Order panels under the "Newspack Network" menu and confirm you see the Order or Subscription there, and that its links point to the Node
* Back to the Node admin, make a change to the Order or Subscription (for example changing its status)
* Confirm the change shows up in the Event Log
* Confirm that the Order or Subscription in the panel shows the updated status

### `donation_new` and `donation_subscription_cancelled`

* In one of the Nodes, make a one-time donation using the Donation block.
* Check that the `donation_new` event shows up in the Hub's Event log
* Wait until another Node pulls the event
* Confirm an account is created (if it wasn't already)
* Authenticate as the reader in the Node that pulled the event
* Open DevTools and confirm there's a reader data entry in the `localStorage` with:

```json
{
  "np_reader_1_network_donor": {
    "https://node.com": {
      "1234567890": "once"
    }
  }
}
```

* Back in the Node that originated the order, place a monthly donation
* Wait for the other node to pull and refresh the authenticated session page
* Confirm the `localStorage` now contains:

```json
{
  "np_reader_1_network_donor": {
    "https://node.com": {
      "1234567890": "once",
      "1234567891": "monthly"
    }
  }
}
```

* Back in the Node that originated the order, cancel the subscription (make sure it's actually cancelled and not 'Pending Cancellation')
* Wait for the other node to pull and refresh the authenticated session page
* Confirm the `localStorage` now contains:

```json
{
  "np_reader_1_network_donor": {
    "https://node.com": {
      "1234567890": "once",
      "1234567891": "monthly",
      "1234567892": "subscription_cancelled"
    }
  }
}
```
