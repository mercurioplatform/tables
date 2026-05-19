<?php

namespace Mercurio\Tables\Tests\Unit\Api;

use Mercurio\Tables\Api\ApiErrorCode;
use Mercurio\Tables\Api\ApiErrorResponse;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Mercurio\Tables\Tests\TestCase;

final class ApiErrorResponseTest extends TestCase
{
    public function test_make_sets_status_from_enum(): void
    {
        $r = ApiErrorResponse::make(ApiErrorCode::ValidationFailed, 'bad');

        $this->assertSame(422, $r->getStatusCode());
        $body = $r->getData(true);
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame('bad', $body['error']['message']);
    }

    public function test_resource_not_found_returns_404(): void
    {
        $this->assertSame(404, ApiErrorResponse::make(ApiErrorCode::ResourceNotFound, 'x')->getStatusCode());
    }

    public function test_mutations_disabled_returns_403(): void
    {
        $this->assertSame(403, ApiErrorResponse::make(ApiErrorCode::MutationsDisabled, 'x')->getStatusCode());
    }

    public function test_malformed_query_returns_400(): void
    {
        $this->assertSame(400, ApiErrorResponse::make(ApiErrorCode::MalformedQuery, 'x')->getStatusCode());
    }

    public function test_source_error_returns_500(): void
    {
        $this->assertSame(500, ApiErrorResponse::make(ApiErrorCode::SourceError, 'x')->getStatusCode());
    }

    public function test_capability_unsupported_returns_422(): void
    {
        $this->assertSame(422, ApiErrorResponse::make(ApiErrorCode::CapabilityUnsupported, 'x')->getStatusCode());
    }

    public function test_field_not_allowed_includes_details(): void
    {
        $r = ApiErrorResponse::fieldNotAllowed('hidden', ['id', 'name']);
        $body = $r->getData(true);

        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame('hidden', $body['error']['details']['field']);
        $this->assertSame(['id', 'name'], $body['error']['details']['allowed']);
    }

    public function test_operator_not_allowed_includes_details(): void
    {
        $r = ApiErrorResponse::operatorNotAllowed('total', 'gte', ['eq', 'in']);
        $body = $r->getData(true);

        $this->assertSame('total', $body['error']['details']['field']);
        $this->assertSame('gte', $body['error']['details']['operator']);
        $this->assertSame(['eq', 'in'], $body['error']['details']['allowed']);
    }

    public function test_capability_unsupported_includes_capability(): void
    {
        $r = ApiErrorResponse::capabilityUnsupported('filter');
        $body = $r->getData(true);

        $this->assertSame('CAPABILITY_UNSUPPORTED', $body['error']['code']);
        $this->assertSame('filter', $body['error']['details']['capability']);
    }

    public function test_from_exception_round_trips_code_and_details(): void
    {
        $e = new ApiValidationException(
            ApiErrorCode::ValidationFailed,
            'bad page',
            ['page' => -1],
        );

        $r = ApiErrorResponse::fromException($e);
        $body = $r->getData(true);

        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame('bad page', $body['error']['message']);
        $this->assertSame(['page' => -1], $body['error']['details']);
    }
}
