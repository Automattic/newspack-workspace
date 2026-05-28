# newspack-network

The Newspack Network

This plugin creates a Network of Newspack sites, in which events that happen in one site are propagated to all other sites in the network.

## Architecutre

This plugin works in a Hub & Spoke architecutre.

The Hub is the central site in the network.

All the other sites are called "Nodes"

All sites send events to the Hub and then pull events that happened in other sites from the Hub.

The Hub holds an Event Log, where we have the history of all events in all sites of the Network.

## Setting up

1. Choose which site will be the Hub
2. In that site, go to Newspack Network > Site Role and set this site as the Hub
3. Go to Newspack Network > Nodes and add the nodes that will be part of the network
4. Note that for each Node, a Secret key is generated. This key will be used to sign requests between Hub and Nodes.
5. Click the "Link the site" button and then "Connect" (on the Node site) to link the sites.

## Techinical docs

See [Dev Notes](DEV_NOTES.md)

## Testing scenarios

This is a new plugin, so on [this page](TESTING_SCENARIOS.md) you'll find several testing scenarios to help you configure the plugin and confirm it's working as expected.
