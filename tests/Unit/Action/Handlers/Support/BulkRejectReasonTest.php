<?php

namespace Mercurio\Tables\Tests\Unit\Action\Handlers\Support;

use Mercurio\Tables\Action\Handlers\Support\BulkRejectReason;
use Mercurio\Tables\Api\ApiErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response as Status;

final class BulkRejectReasonTest extends TestCase
{
    #[DataProvider('reasonMatrixProvider')]
    public function test_log_suffix_and_api_error_code_match_expected(
        BulkRejectReason $reason,
        string $expectedLogSuffix,
        ApiErrorCode $expectedApiErrorCode,
        int $expectedHttpStatus,
    ): void {
        $this->assertSame($expectedLogSuffix, $reason->logSuffix());
        $this->assertSame($expectedApiErrorCode, $reason->apiErrorCode());
        $this->assertSame($expectedHttpStatus, $reason->apiErrorCode()->httpStatus());
    }

    /**
     * @return array<string, array{0: BulkRejectReason, 1: string, 2: ApiErrorCode, 3: int}>
     */
    public static function reasonMatrixProvider(): array
    {
        return [
            'UnknownAction' => [
                BulkRejectReason::UnknownAction,
                'unknown_action',
                ApiErrorCode::ActionNotFound,
                Status::HTTP_NOT_FOUND,
            ],
            'MutateDenied' => [
                BulkRejectReason::MutateDenied,
                'mutate_denied',
                ApiErrorCode::CapabilityUnsupported,
                Status::HTTP_UNPROCESSABLE_ENTITY,
            ],
            'EmptyIds' => [
                BulkRejectReason::EmptyIds,
                'empty_ids',
                ApiErrorCode::ValidationFailed,
                Status::HTTP_UNPROCESSABLE_ENTITY,
            ],
            'ProbeMissing' => [
                BulkRejectReason::ProbeMissing,
                'probe_missing',
                ApiErrorCode::RecordNotFound,
                Status::HTTP_NOT_FOUND,
            ],
            'Forbidden' => [
                BulkRejectReason::Forbidden,
                'forbidden',
                ApiErrorCode::PolicyDenied,
                Status::HTTP_FORBIDDEN,
            ],
            'NoHandlerConfigured' => [
                BulkRejectReason::NoHandlerConfigured,
                'no_handler',
                ApiErrorCode::MutationFailed,
                Status::HTTP_INTERNAL_SERVER_ERROR,
            ],
            'CallbackThrew' => [
                BulkRejectReason::CallbackThrew,
                'callback_threw',
                ApiErrorCode::MutationFailed,
                Status::HTTP_INTERNAL_SERVER_ERROR,
            ],
        ];
    }
}
