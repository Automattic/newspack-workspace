
# Premises

These are some of the premises this plugin was built on. They serve as guidelines for implementing new features and support for new events.

Syncing data over the network is tricky, because if something goes wrong during one particular request, you miss a sync event and you can have data loss.

This plugin takes some measures to build a more reliable and scalable sync workflow.

## Redundancy - never trust a request is going to be successful

When a Node pushes an event to the Hub, it uses Newspack's Webhook strategy. This strategy includes a retry system that will try to send the event again if the first attempt fails. It will try it several times.

On the other end of the sync, Nodes pull events from the Hub on a regular basis and only update their internal count after an event is successfully processed. This means that if a pull request fails, the next one will pick up from where the last one left.

## Traceability - we need to be able to inspect the sync process and errors should be visible

When things go wrong with sync, we need to be able to see it and find where things got off track to fix them.

By using webhooks to send events to the Hub, each attempt to send data is stored in the Hub. You can see the list of queued webhooks in Newspack Network > Node Settings.

If webhooks fail, you will see the errors in this page. If all the retries expire, the failed webhooks will remain there until you fix the connection and then you can manually send them.

In this same page you can see if the pulling is coming fine. If there's an error in the pulling process, you will also see an error here.

Every communication between Nodes and Hub is visible and if something goes wrong an error is surfaced. There should be no requests that fail silently.

The Event Log is also a big part of the efforts to make the events traceable. Having a central table with the history of everything that happened across the whole network makes it easier to follow what's happening.

## Scalability - this plugins should work fine with dozens (or hundreds) of sites in the Network

The sync workflow is built in a way that it should work just fine even if there are hundreds of sites in the network.

The sync for each Node happens in its own pace, and it doesn't matter if there are many sites in the network. That's why Nodes pull event from the Hub, and it's not the Hub who is looping through the Nodes and pushing them information.

The Nodes send each event in a small payload to the Hub, one at a time, so it should not be a problem for the Hub to handle them.

The only piece that can become a bottleneck for very large networks is the Event Log. That's why this is built in a custom DB table that does not interfere with the site and that can accomodate millions of records if needed. But when the time comes, we might need to implement a strategy to clean up and archive old events in a separate table.

## Performance - Even though we are dealing with a lot of data, the plugin should not slow the site down

One of the reasons to rely on Newspack's Data Events API is because it is designed to work asynchronously, in the background, and never keep any user hanging waiting for Data events to be triggered or processed.

Same thing applies to how Webhooks are handled and how Pull requests are triggered by WP Cron. Everything happens in dedicated, async requests, and there should not be an action in this plugin that influences site's speed and performance.

## Events' processing is idempotent - it should be harmless to process the same event over and over again

By definition you should be able to run the same event multiple times and they will not end up creating multiple instances of the same thing.

If, for any reason, we had to put a site to pull all the events from the Event Log back from the start, at the end of the process the site would be in the same state.

# Data flow

Newspack Network works on the top of the Data Events API present in the Newspack plugin. This plugin will listen to some of the Data Events and propagate them to all sites in the network.

When events happen in a site, they are pushed to the `Event Log` in the Hub. Each Node will pull new events from this `Event Log` every five minutes.

It all starts with the `Accepted_Actions` class. There we define which Data Events will be propagated via the Network plugin. When the Newspack plugin's Data Events API fire any of these events, it will be captured by this plugin and propagated.

Let's see how this work by following a couple of examples:

## Events triggered in a Node

For events that happen in one of the Nodes in the network, here's what happens.

First, the plugin will register a `Webhook` in the Newspack plugin, that will send all events delcared in `Accepted_Actions` as webhooks to the Hub. This is done in `Node\Webhook`.

Once the webhook is sent, the Hub receives and process it. This happens in `Hub\Webhook`.

When received, each event is represented by an `Inconming_Event` object. There is one specific class for each one of the `Accepted_Actions`, and they all extend `Incoming_Event`.

Every event is persisted in the `Event_Log`. This is done in `Incoming_Event::process_in_hub()` and uses `Hub\Stores\Event_Log`, which stores the information in a custom DB table.

After the event is persisted in the `Event_Log` it may fire additional post processing routines, depending on the event. These routines will be defined in a method called `post_process_in_hub()` present in each `Inconming_Event` class.

For example, the `reader_registered` event will create or update a WP User in the site, and the Woocommerce events will update the Woo items used to build the central Dashboards.

## Events triggered in the Hub

The Hub can also be an active site in the network. So events that happen on the Hub should also be propagated as if it was just another node.

But when an event is fired in the Hub, there's no need to send a webhook request to itself. So we simply listen to events fired by the Data Events API and trigger the process to persist it into the `Event_Log`. This is done by `Hub\Event_Listeners` and it basically does the same thing `Hub\Webhook` does, but it is listening to local events instead of receiving webhook requests from a Node.

## Nodes pulling events from the hub

Once events are persisted in the `Event_Log`, Nodes will be able to pull them and update their local databases.

They do this by making requests to the Hub every five minutes. In each request, they send along what is the ID of the last item they processed last time they pulled data from the Hub.

This pulling is done in `Node\Pulling`. In the Hub, the API endpoint that handles the pull requests coming from the nodes is registered in `Hub\Pull_Endpoint`.

Note that nodes are not necessarily interested in every event. So you will see that the `Accepted_Actions` class has a `ACTIONS_THAT_NODES_PULL` property that defines what action Nodes will ask for.

When they pull events, they get an array of events. Each event has a an action name, that maps to a `Incoming_Event`, just as they do when the Hub receives a webhook request from the Node.

The Node instantiates the corresponding `Incoming_Event` for each action and then calls the `process_in_node` method of the event object.

## Stores

Stores are simple abstraction layers used by the Hub to persist data on the database. They are used to store and read data.

For example, `Event_log` items are stored in a custom table, while Woo orders and subscriptions that are used to build the centralized dashboards are stored as custom post types. But it's all done via stores.

Each store has a `-item` respective class that will be used to represent the item they store. When you fetch items from a store, the respective item object will be returned.

For example, `class-subscriptions` store has its respective `class-subscription-item` item class.

`Event_Log` items are defined in the `event-log-items` folder as there is one class for each different action. The only thing they do is define a different `get_summary` method that will define what will be displayed in the Event Log admin table. If there is not a specific class for a given action, it will use the `Generic` class.

These items are used by Admin classes to display items in Admin Dashboards and will also be used by the classes that will serve this events to the Nodes when they pull events from the Hub.

## Database

Classes that creates the buckets where information used by Stores are stored. Creates database tables or register custom post types.

## Admin

Classes that handle user facing admin pages

## Adding support to a new action

These are the steps to add support to a new action:

1. Add it to `ACCEPTED_ACTIONS`

Edit `class-accepted-actions.php` and add a new item to the array. The key is the action name as defined in the Newspack Data API, and the value will be the class name used in this plugin. For example, this will define the class name of the `Incoming_Event` child class.

If all you need is to have this event persisted in the Hub. That's it.

If you also want this event to be pulled by all Nodes in the network, also add the action to the `ACTIONS_THAT_NODES_PULL` constant.

2. Create an `Incoming_Event` child class

In the `incoming-events` folder, create a new file named after the class name you informed in `ACCEPTED_ACTIONS`.

At first, this class doesn't need to have anything on it.

At this point, the event will be propagated and will be stored in the `Event Log`.

But it's very unlikely that won't do anything else with an event, so let's add other methods.

3. Implement methods to process the event.

There are 3 methods you can implement to do something with the event.

* `process_in_node`: Will be invoked when a Node pulls a new event that happened in another site in the network (the Hub or another Node)
* `post_process_in_hub`: Will be invoked when the Hub receives a new event coming from a Node in the Network. It's called "post" process because every event is processed in the hub so they can get added to the Event log. So this means it will be invoked after the basic processing is done, which is to add it to the Event Log.
* `always_process_in_hub`: Will always be invoked after a new event is added to the Event Log. Similar to `post_process_in_hub` but will also be invoked when the event was triggered by the Hub itself.

Depending on what you want to do with the event, and where, implement one or more of these 3 methods to perform some actions when this event is detected both in the hub and in the nodes. You can create a user, a post, add user or post meta, etc.

Examples:
* `canonical_url_updated` is triggered by the hub, and it has only the `process_in_node` method because the Hub will never receive it from a Node and won't do anything additional after it's triggered
* `order_changed`: is an event that the Nodes don't care about, so `process_in_node` is not present. And also, changes in Woo Orders need to be persisted in the central Woo dashboard, even for orders that belong to the Hub, so it uses `always_process_in_hub`.
* `user_updated`: is an event that can happen in any site and all other sites need to update their local users. In this case, you have the same thing happening for `process_in_node` and `post_process_in_hub`. It doesn't matter if it's coming from a Node to the Hub, from the Hub to a node, or from a Node to another Node. Every site will treat this event the same way.

4. Optional. Create a `event-log-item` specific class

If you want to customize how this new event looks in the `Event Log`, go to `hub/stores/event-log-items` and create a new class named after the class you informed in `ACCEPTED_ACTIONS`. Implement the `get_summary` method to display the information the way you need.

## WP CLI

Available CLI commands are (add `--help` flag to learn more about each command):

### `wp newspack-network process-webhooks`
* Will process `pending` `np_webhook_request`s and delete after processing.
* `--per-page=1000` to process x amount of requests. Default is `-1`.
* `--status='killed'` to process requests of a different status. Default is `'pending'`
* `--dry-run` enabled. Will run through process without deleting.
* `--yes` enabled. Will bypass confirmations.


### `wp newspack-network sync-all`
* Will pull all events from the Hub
* `--yes` Run the command without confirmations

## Troubleshooting

Here's how to debug and follow each event while they travel around.

First, make sure to add the `NEWSPACK_NETWORK_DEBUG` constant as `true` to every site wp-config file.

All log messages will include the process id (pid) as the first part of the message in between brackets. This is helpful to identify things happening in different requests. When debugging multiple parallel async actions, sometimes they get mixed up in the log.

### When an event is fired in a Node

Newspack Network will listen to the Newspack Data Events API.

When an event dispatched in a Node, it will create a new webhook request. See [Data Events Webhooks](https://github.com/Automattic/newspack-plugin/blob/trunk/includes/data-events/class-webhooks.php) for details on how it works.

In short, a webhook is a Custom Post type post scheduled to be published in the future. Once it's published, the request is sent. If it fails, it schedules itself again for the future, incresing the wait time in a geometric progression.

You can see the scheduled webhook requests in Newspack Network > Node Settings under the "Events queue" section.

* If you want to manually and immeditally send a webhook request, you can do so using `Newspack\Data_Events\Webhooks::process_request( $request_id )`

When the request is sent, Webhooks will output a message starting with `[NEWSPACK-WEBHOOKS] Sending request` in the logs.

When the request reaches the hub, you will see it on the Logs starting with a `Webhook received` message.

### When the Node pulls events from the Hub

At any point, it's a good idea to check the value for the `newspack_node_last_processed_action` option. It holds the ID of the last event received in the last pull.

Pulls are scheduled in CRON for every 5 minutes. If you want to trigger a pull now, you can do so by calling `Newspack_Network\Node\Pulling::pull()`

In the Node's log you will see detailed information about the pull attempt, starting with a `Pulling data` message.

In the Hub's log, you will also see detailed information about the pull request, starting with a `Pull request received` message.
