<?php

namespace BetterFCF;

if (! defined('ABSPATH')) {
    exit;
}

class Plugin
{
    public function boot()
    {
        add_action('init', [$this, 'onInit'], 5);

        (new Conversational\Assets())->register();
        (new Numbers\FieldSettings())->register();
        (new Partials\CaptureController())->register();
        (new Partials\Conversion())->register();
        (new Partials\Abandonment())->register();
        (new Admin\PartialsTab())->register();
    }

    /**
     * BaseFieldManager and IntegrationManagerController both hang themselves off
     * Fluent Forms' filters at construction time, and Fluent Forms registers its
     * own on `init`, so we do the same rather than constructing them earlier.
     */
    public function onInit()
    {
        Database\Migrator::maybeMigrate();

        new Elements\PartialStore();
        new Webhooks\WebhookIntegration(wpFluentForm());
    }
}
