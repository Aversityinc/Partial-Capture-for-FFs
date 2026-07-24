# Partial Capture for Fluent Forms

Fluent Forms has a conversational form mode, the kind that shows one question at a time. It looks great, but it has no way to capture people who start the form and leave before they submit. If someone answers a few questions, gives you their phone number, and then closes the tab, that lead is gone.

Fluent Forms Pro does have a partial entries feature, but only on regular forms, not conversational ones. And even there it just stores the half finished entry in the database. It won't send that data anywhere, so there's no way to push a partial to a webhook or a CRM while the person is still filling things out.

This plugin covers both gaps. It captures partial submissions from conversational forms, and it can send them to a webhook as the visitor moves through the form, so you can follow up on the people who didn't finish.

It works with the free Fluent Forms plugin. You don't need Pro.

## What it does

### Capture partial leads

You drop a "Partial Store" element into the form wherever you want capture to begin. Anything the person answers before that point stays in their browser and is never sent. Once they get past it, the plugin saves what they've entered so far and keeps the record updated as they keep going.

If they pause on the form or leave the page, the partial is queued for your webhooks — and by default it is held for a short grace window (3 minutes, configurable) before it actually goes out. If the person comes back and keeps answering, or finishes and submits, the queued send is cancelled. That grace window is what stops the classic double-submission problem, where a CRM receives someone as a "partial lead" seconds before their real submission arrives. Set the window to 0 if you'd rather have the webhook the moment they pause.

A partial that turns into a real submission is removed from the partial list entirely — from that moment it lives in Fluent Forms' own Entries, and nothing about it is ever sent as a partial again.

You can add more than one Partial Store to a long form to capture at a few different points. Captured leads show up in a "Partial Leads" tab on the form, with the stage they reached, how long they spent, and the answers they gave. You can export the whole list to CSV.

### Send partials to a webhook

Webhook feeds live in the normal Fluent Forms integrations screen, so setting one up feels the same as any other integration. You map the fields you want to send, choose when it should fire (when someone pauses on a checkpoint, or when they leave the page), and you can send the person's answers along with a few extra bits like which checkpoint they reached, how long they were on the page, and any UTM tags from the landing URL.

There's one option that matters a lot for partials: you can tell a feed to only send when certain fields are filled in. Most of the answers on a partial are still empty, so this lets you hold off until there is actually a phone number or an email before anything goes out. Fluent Forms' own conditional logic can't express that, which is part of why the plugin ships its own.

### Format number fields

Three checkboxes on any number field let you show a dollar sign in front, a percent sign after, or comma grouping as the person types. They see $450,000, but the value you store and send stays 450000, so calculations and validation keep working.

## Requirements

- Fluent Forms 5.2 or newer (tested on 5.2 and 6.2)
- PHP 7.4 or newer

## Installing

Download the latest zip, or clone this repo into your `wp-content/plugins` folder. Activate it, open a conversational form, and add a Partial Store element where you want capture to start. Webhooks are set up under the form's Settings and Integrations screen.

## License

GPLv2 or later.
