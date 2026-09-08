<?php

use App\Base\Database\DTO\DataShare\DataShareExportResult;
use App\Base\Database\DTO\DataShare\DataShareInstanceIdentity;
use App\Base\Database\DTO\DataShare\DataSharePackageExpectation;
use App\Base\Database\DTO\DataShare\DataShareTransferOfferBundle;
use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Models\DataShareReceipt;
use App\Base\Database\Models\DataShareTransferOffer;
use App\Base\Database\Services\DataShare\DataSharePackageExporter;
use App\Base\Database\Services\DataShare\DataSharePackageInbox;
use App\Base\Database\Services\DataShare\DataSharePackageReader;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

if (! defined('GENERIC_SHARE_SCOPE')) {
    define('GENERIC_SHARE_SCOPE', 'tests/fixtures/data-share');
    define('GENERIC_SHARE_PARENT', 'test_data_share_parents');
    define('GENERIC_SHARE_CHILD', 'test_data_share_children');
    define('GENERIC_SHARE_SOURCE_NAME', 'Generic source');
    define('GENERIC_SHARE_BINARY_PAYLOAD', "\x00\xFFshare");
    define('GENERIC_SHARE_PRIMARY_URL', 'https://source.lan:8443');
    define('GENERIC_SHARE_FALLBACK_URL', 'https://share.example.test');
    define('GENERIC_SHARE_OFFER_PATH', '/data-share/offers/');
    define('GENERIC_SHARE_NDJSON', 'application/x-ndjson');
    define('GENERIC_SHARE_RECEIVING_PATH', 'data-share/receiving');
    define('GENERIC_SHARE_DESTINATION_NAME', 'Generic destination');
}

if (! function_exists('setGenericDataShareSettings')) {
    /** @param array<string, mixed> $values */
    function setGenericDataShareSettings(array $values): void
    {
        $settings = app(SettingsService::class);

        foreach ($values as $key => $value) {
            $settings->set($key, $value);
        }
    }
}

if (! function_exists('genericShareBinaryStream')) {
    function genericShareBinaryStream(string $bytes): mixed
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}

if (! function_exists('seedGenericDataShareFixture')) {
    function seedGenericDataShareFixture(): void
    {
        DB::table(GENERIC_SHARE_PARENT)->insert([
            [
                'id' => 2,
                'code' => 'parent-2',
                'nullable_alias' => null,
                'name' => 'Éclair شركة',
                'metadata' => json_encode(['nested' => ['ready' => true]], JSON_THROW_ON_ERROR),
                'effective_on' => '2026-07-10',
                'amount' => '12.3400',
                'payload' => genericShareBinaryStream(GENERIC_SHARE_BINARY_PAYLOAD),
            ],
            [
                'id' => 10,
                'code' => 'parent-10',
                'nullable_alias' => null,
                'name' => 'Ten',
                'metadata' => null,
                'effective_on' => null,
                'amount' => '0.5000',
                'payload' => null,
            ],
        ]);
        DB::table(GENERIC_SHARE_CHILD)->insert([
            'id' => 25,
            'parent_id' => 10,
            'external_code' => 'child-25',
            'note' => 'Relationship must keep the physical parent key.',
        ]);
    }
}

if (! function_exists('becomeGenericDataShareSource')) {
    function becomeGenericDataShareSource(): DataShareInstanceIdentity
    {
        setGenericDataShareSettings([
            'data_share.instance.id' => 'generic-source-dev',
            'data_share.instance.name' => GENERIC_SHARE_SOURCE_NAME,
            'data_share.instance.role' => 'development',
        ]);

        return new DataShareInstanceIdentity('generic-source-dev', GENERIC_SHARE_SOURCE_NAME, DataShareInstanceRole::Development);
    }
}

if (! function_exists('becomeGenericDataShareDestination')) {
    function becomeGenericDataShareDestination(bool $production = false): DataShareInstanceIdentity
    {
        $role = $production ? DataShareInstanceRole::Production : DataShareInstanceRole::Staging;
        $id = $production ? 'generic-destination-production' : 'generic-destination-stage';

        setGenericDataShareSettings([
            'data_share.instance.id' => $id,
            'data_share.instance.name' => GENERIC_SHARE_DESTINATION_NAME,
            'data_share.instance.role' => $role->value,
        ]);

        return new DataShareInstanceIdentity($id, GENERIC_SHARE_DESTINATION_NAME, $role);
    }
}

if (! function_exists('publishGenericDataShare')) {
    /** @return array{bundle: DataShareTransferOfferBundle, offer: DataShareTransferOffer, export: DataShareExportResult} */
    function publishGenericDataShare(
        array $tables = [GENERIC_SHARE_PARENT, GENERIC_SHARE_CHILD],
    ): array {
        becomeGenericDataShareSource();
        $exporter = app(DataSharePackageExporter::class);
        $preview = $exporter->preview(GENERIC_SHARE_SCOPE, $tables);
        $bundle = app(DataShareTransferOfferManager::class)->publish(
            GENERIC_SHARE_SCOPE,
            $tables,
            $preview->previewHash,
            actorId: 9001,
        );
        $offer = DataShareTransferOffer::query()->where('offer_id', $bundle->offerId)->firstOrFail();
        $stream = Storage::disk('local')->readStream($offer->package_path);

        try {
            $manifest = app(DataSharePackageReader::class)->manifest($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'bundle' => $bundle,
            'offer' => $offer,
            'export' => new DataShareExportResult(
                $offer->package_id,
                $offer->package_path,
                $offer->package_sha256,
                $offer->bytes,
                $manifest,
            ),
        ];
    }
}

if (! function_exists('receiveGenericDataShare')) {
    function receiveGenericDataShare(DataShareTransferOfferBundle $bundle, DataShareExportResult $export, bool $production = false): DataShareReceipt
    {
        becomeGenericDataShareDestination($production);

        return app(DataSharePackageInbox::class)->receiveFromProtectedPath(
            $export->path,
            DataSharePackageExpectation::fromOffer($bundle),
        );
    }
}
