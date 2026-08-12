<?php

namespace App\Http\Controllers;

use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Http\Requests\StoreLocalWebhookLeadRequest;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Http\JsonResponse;
use Throwable;

class LocalWebhookController extends Controller
{
    public function store(
        StoreLocalWebhookLeadRequest $request,
        LeadIngestionService $ingestion,
    ): JsonResponse {
        $attributes = $request->validated('envelope.lead');
        $externalReference = $attributes['external_reference'];
        unset($attributes['external_reference'], $attributes['source']);

        try {
            $result = $ingestion->ingest(
                LeadSource::Webhook,
                $externalReference,
                $attributes,
            );
        } catch (Throwable) {
            return response()->json(['result' => 'unavailable'], 500);
        }

        return response()->json([
            'result' => $result->created ? 'created' : 'already_received',
            'lead' => [
                'id' => $result->lead->getKey(),
                'status' => $result->lead->status->value,
            ],
        ], $result->created ? 201 : 200);
    }
}
