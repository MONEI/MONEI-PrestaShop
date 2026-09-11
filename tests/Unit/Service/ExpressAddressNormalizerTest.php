<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PsMonei\Service\Express\ExpressAddressNormalizer;

/**
 * PayPal returns a partial address when the PayPal account has none saved: name,
 * email and country, with no street. PrestaShop validates addresses strictly, so
 * the payment would fail after the shopper had already approved it.
 */
class ExpressAddressNormalizerTest extends TestCase
{
    public function testAFullAddressPassesThroughUnchanged(): void
    {
        $result = ExpressAddressNormalizer::normalize([
            'name' => 'Ada Lovelace',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'zip' => '28013',
            'country' => 'ES',
            'phone' => '600000000',
        ]);

        $this->assertSame('Ada', $result['firstName']);
        $this->assertSame('Lovelace', $result['lastName']);
        $this->assertSame('Calle Mayor 1', $result['address1']);
        $this->assertSame('Madrid', $result['city']);
        $this->assertSame('28013', $result['postcode']);
        $this->assertSame('ES', $result['countryIso']);
        $this->assertFalse($result['incomplete']);
    }

    public function testAPartialPayPalAddressIsFilledAndFlagged(): void
    {
        $result = ExpressAddressNormalizer::normalize([
            'name' => 'Ada Lovelace',
            'country' => 'ES',
        ]);

        $this->assertTrue($result['incomplete'], 'the merchant has to be able to see this needs completing');
        $this->assertSame(ExpressAddressNormalizer::PLACEHOLDER, $result['address1']);
        $this->assertSame(ExpressAddressNormalizer::PLACEHOLDER, $result['city']);
        $this->assertSame('ES', $result['countryIso']);
        // Names survive: PayPal does return those.
        $this->assertSame('Ada', $result['firstName']);
        $this->assertSame('Lovelace', $result['lastName']);
    }

    public function testWalletsThatSpellTheFieldsDifferentlyAreUnderstood(): void
    {
        // Apple Pay, Google Pay and PayPal disagree on every one of these names.
        $result = ExpressAddressNormalizer::normalize([
            'fullName' => 'Ada Lovelace',
            'addressLine1' => 'Calle Mayor 1',
            'locality' => 'Madrid',
            'postalCode' => '28013',
            'countryCode' => 'es',
            'phoneNumber' => '600000000',
        ]);

        $this->assertSame('Calle Mayor 1', $result['address1']);
        $this->assertSame('Madrid', $result['city']);
        $this->assertSame('28013', $result['postcode']);
        $this->assertSame('ES', $result['countryIso'], 'the country code is normalised to upper case');
        $this->assertSame('600000000', $result['phone']);
        $this->assertFalse($result['incomplete']);
    }

    public function testASingleWordNameStillFillsBothRequiredFields(): void
    {
        $result = ExpressAddressNormalizer::normalize(['name' => 'Ada', 'country' => 'ES']);

        $this->assertSame('Ada', $result['firstName']);
        $this->assertSame('Ada', $result['lastName']);
    }

    public function testAMultiWordSurnameIsKeptTogether(): void
    {
        $this->assertSame(
            ['Ada', 'King Lovelace'],
            ExpressAddressNormalizer::splitName('Ada King Lovelace')
        );
    }

    public function testAMissingPhoneDoesNotMakeTheAddressIncomplete(): void
    {
        $result = ExpressAddressNormalizer::normalize([
            'name' => 'Ada Lovelace',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'zip' => '28013',
            'country' => 'ES',
        ]);

        $this->assertSame('', $result['phone']);
        $this->assertFalse($result['incomplete'], 'a phone number is not required by PrestaShop');
    }

    /**
     * The state used to be dropped on the floor, and the order builder then
     * assigned the country's first state to every address that needed one —
     * which for the US is "AA", Armed Forces Americas. Each wallet spells the
     * field differently, so every spelling has to survive normalisation.
     */
    public function testTheStateIsCarriedThroughFromEveryWalletSpelling(): void
    {
        $this->assertSame('TX', ExpressAddressNormalizer::normalize(['administrativeArea' => 'TX'])['state']);
        $this->assertSame('TX', ExpressAddressNormalizer::normalize(['state' => 'TX'])['state']);
        $this->assertSame('TX', ExpressAddressNormalizer::normalize(['region' => 'TX'])['state']);
        $this->assertSame('TX', ExpressAddressNormalizer::normalize(['admin_area_1' => 'TX'])['state']);
        $this->assertSame('Texas', ExpressAddressNormalizer::normalize(['province' => 'Texas'])['state']);
    }

    public function testAMissingStateIsEmptyNotInvented(): void
    {
        // The builder refuses an empty state for a country that needs one. It
        // must see the absence, not a placeholder that happens to look valid.
        $this->assertSame('', ExpressAddressNormalizer::normalize(['city' => 'Austin'])['state']);
    }

    /**
     * monei.js v3 emits BillingDetails: name, email and phone at the top and the
     * address nested under `address`, with `line1`, `zip`, `country` and `state`.
     * The first client read the address off the top level and got nothing, so
     * every real wallet payment for a physical cart failed after approval. This
     * is the shape from the SDK's own typings and apple-pay.ts / google-pay.ts.
     */
    public function testTheSdkBillingDetailsShapeIsUnderstood(): void
    {
        $result = ExpressAddressNormalizer::normalize([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '600000000',
            'address' => [
                'line1' => '1 Main St',
                'line2' => 'Apt 4',
                'city' => 'Austin',
                'zip' => '73301',
                'state' => 'TX',
                'country' => 'US',
            ],
        ]);

        $this->assertFalse($result['incomplete']);
        $this->assertSame('Ada', $result['firstName']);
        $this->assertSame('Lovelace', $result['lastName']);
        $this->assertSame('1 Main St', $result['address1']);
        $this->assertSame('Apt 4', $result['address2']);
        $this->assertSame('Austin', $result['city']);
        $this->assertSame('73301', $result['postcode']);
        $this->assertSame('TX', $result['state']);
        $this->assertSame('US', $result['countryIso']);
        $this->assertSame('ada@example.com', $result['email']);
        $this->assertSame('600000000', $result['phone']);
    }

    public function testTheSecondAddressLineIsKept(): void
    {
        // An apartment or suite used to be dropped, leaving the carrier a street
        // with no unit to deliver to.
        $this->assertSame('Suite 9', ExpressAddressNormalizer::normalize(['addressLine2' => 'Suite 9'])['address2']);
        $this->assertSame('', ExpressAddressNormalizer::normalize(['line1' => 'x'])['address2']);
    }

    public function testAnEmptyPayloadIsHandled(): void
    {
        $result = ExpressAddressNormalizer::normalize([]);

        $this->assertTrue($result['incomplete']);
        $this->assertSame(ExpressAddressNormalizer::PLACEHOLDER, $result['firstName']);
        $this->assertSame('', $result['countryIso']);
    }
}
