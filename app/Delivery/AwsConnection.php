<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

use Aws\SesV2\SesV2Client;
use Aws\Sns\SnsClient;
use Crab\Http\HttpException;

final class AwsConnection
{
    public function __construct(
        private ?SesV2Client $ses = null,
        private ?SnsClient $sns = null,
    ) {}

    public function region(): string
    {
        $region = (string) config('ses.region');
        // This slice supports the commercial AWS partition only; never accept arbitrary endpoints.
        if (!preg_match('/^(us|eu|ap|ca|sa|me|af|il|mx)-(?:[a-z]+-)?[a-z]+-\d$/D', $region)) {
            throw new HttpException(503, 'Set AWS_REGION to a supported commercial AWS region.');
        }
        return $region;
    }

    public function ses(): SesV2Client
    {
        $this->enabled();
        return $this->ses ??= new SesV2Client($this->options());
    }

    public function sns(): SnsClient
    {
        $this->enabled();
        return $this->sns ??= new SnsClient($this->options());
    }

    public function topic(): string
    {
        $topic = (string) config('ses.topic_arn');
        if (!preg_match(
            '/^arn:aws:sns:' . preg_quote($this->region(), '/') . ':\d{12}:[A-Za-z0-9_-]{1,256}$/D',
            $topic,
        )) {
            throw new HttpException(422, 'Set SES_SNS_TOPIC_ARN to an SNS topic in the same AWS region.');
        }
        return $topic;
    }

    public function configurationSet(int $workspace): string
    {
        $prefix = (string) config('ses.configuration_prefix');
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $prefix)) {
            throw new HttpException(503, 'Invalid SES_CONFIGURATION_PREFIX.');
        }
        return $prefix . '-workspace-' . $workspace;
    }

    public function authorize(array $input): void
    {
        $this->enabled();
        if (config('ses.setup_enabled') !== true) {
            throw new HttpException(403, 'The operator must enable SES_SETUP_ENABLED before AWS resources can change.');
        }
        if (($input['authorize_aws'] ?? null) !== true) {
            throw new HttpException(422, 'Confirm the described AWS changes first.');
        }
    }

    public function endpoint(int $workspace): string
    {
        $url = rtrim((string) config('app.url'), '/');
        if (
            !str_starts_with($url, 'https://')
            || !filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_PATH)
        ) {
            throw new HttpException(422, 'Feedback requires a public HTTPS APP_URL without a path.');
        }
        return $url . '/webhooks/ses/' . $workspace;
    }

    private function enabled(): void
    {
        if (config('ses.enabled') !== true) {
            throw new HttpException(
                503,
                'SES is not connected. Configure server credentials and set SES_ENABLED=true.',
            );
        }
    }

    private function options(): array
    {
        return [
            'region' => $this->region(),
            'version' => 'latest',
            'retries' => 0,
            'http' => ['connect_timeout' => 3, 'timeout' => 8, 'allow_redirects' => false],
        ];
    }
}
