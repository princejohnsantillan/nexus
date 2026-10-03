<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\PayMongo;
use App\Billing\PayMongoWebhook;
use App\Billing\WebhookEvent;
use App\Exceptions\PayMongoRequestFailed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nexus:paymongo:webhook
    {url? : The public HTTPS URL PayMongo sends events to (this Nexus\'s own webhook unless given)}
    {--list : List the webhooks registered with PayMongo instead, without their secrets}')]
#[Description('Register Nexus\'s webhook with PayMongo for paid checkouts and print its secret once, or list the webhooks registered')]
class RegisterPayMongoWebhookCommand extends Command
{
    /**
     * Register the webhook in the secret key's mode (test or live), or list
     * the ones registered in it. A webhook's secret is printed only when it
     * is registered: listing never shows one, though PayMongo sends them.
     */
    public function handle(PayMongo $payMongo): int
    {
        if (! PayMongo::isSetUp()) {
            $this->components->error(__('Payments aren\'t set up on this Nexus yet: set PAYMONGO_SECRET_KEY first.'));

            return self::FAILURE;
        }

        try {
            return $this->option('list') === true ? $this->list($payMongo) : $this->register($payMongo);
        } catch (PayMongoRequestFailed $failed) {
            $this->components->error($failed->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @throws PayMongoRequestFailed
     */
    private function register(PayMongo $payMongo): int
    {
        $url = $this->argument('url') ?? route('webhooks.paymongo');

        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $this->components->error(__('PayMongo sends events only to a public HTTPS URL, such as https://{your Nexus}/webhooks/paymongo.'));

            return self::FAILURE;
        }

        $webhook = $payMongo->createWebhook($url, [WebhookEvent::CHECKOUT_PAID]);

        $this->components->info(__('Registered :mode webhook :id for :events at :url.', [
            'mode' => $this->modeOf($webhook),
            'id' => $webhook->id,
            'events' => implode(', ', $webhook->events),
            'url' => $webhook->url,
        ]));

        if ($webhook->secretKey === null) {
            $this->components->error(__('PayMongo didn\'t send the webhook\'s secret. Disable the webhook in PayMongo\'s dashboard and register another.'));

            return self::FAILURE;
        }

        $this->line('PAYMONGO_WEBHOOK_SECRET='.$webhook->secretKey);
        $this->newLine();
        $this->line(__('Nexus shows this secret only now, and doesn\'t keep it. Set it in the environment (.env locally, a secret on Laravel Cloud): until then, Nexus refuses every delivery.'));

        return self::SUCCESS;
    }

    /**
     * @throws PayMongoRequestFailed
     */
    private function list(PayMongo $payMongo): int
    {
        $webhooks = $payMongo->webhooks();

        if ($webhooks === []) {
            $this->components->info(__('PayMongo has no webhooks registered for this secret key.'));

            return self::SUCCESS;
        }

        $this->table(
            [__('ID'), __('URL'), __('Events'), __('Status'), __('Mode')],
            array_map(fn (PayMongoWebhook $webhook): array => [
                $webhook->id,
                $webhook->url,
                implode(', ', $webhook->events),
                $webhook->status,
                $webhook->livemode ? __('live') : __('test'),
            ], $webhooks),
        );

        return self::SUCCESS;
    }

    private function modeOf(PayMongoWebhook $webhook): string
    {
        return $webhook->livemode ? __('a live-mode') : __('a test-mode');
    }
}
