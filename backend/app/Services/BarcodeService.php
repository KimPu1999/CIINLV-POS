<?php

namespace App\Services;

use App\Models\ProductVariant;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class BarcodeService
{
    public function validate(?string $barcode, ?string $type): ?string
    {
        if ($barcode === null || $barcode === '') {
            return null;
        }
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }
        $type = strtolower((string) $type);
        $valid = match ($type) {
            'ean-13' => $this->checkDigit($barcode, 13),
            'ean-8' => $this->checkDigit($barcode, 8),
            'upc-a' => $this->checkDigit($barcode, 12),
            'code-128' => preg_match('/^[\x20-\x7E]{1,80}$/', $barcode) === 1,
            'internal', 'manual' => preg_match('/^[A-Za-z0-9._-]{1,100}$/', $barcode) === 1,
            default => false,
        };
        if (! $valid) {
            throw new UnprocessableEntityHttpException('The barcode does not match its declared symbology.');
        }

        return $barcode;
    }

    public function generateInternal(int $tenantId, ProductVariant $variant): string
    {
        do {
            $barcode = 'CIINLV-'.$tenantId.'-'.$variant->getKey().'-'.strtoupper(bin2hex(random_bytes(3)));
        } while (ProductVariant::query()->where('tenant_id', $tenantId)->where('barcode', $barcode)->exists());

        return $barcode;
    }

    private function checkDigit(string $barcode, int $length): bool
    {
        if (strlen($barcode) !== $length || ! ctype_digit($barcode)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < $length - 1; $i++) {
            $sum += (int) $barcode[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10 === (int) $barcode[$length - 1];
    }
}
