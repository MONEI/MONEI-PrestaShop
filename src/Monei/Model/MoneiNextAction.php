<?php


namespace Monei\Model;

use Monei\Traits\Mappeable;
use Monei\Traits\ModelHelpers;

class MoneiNextAction implements ModelInterface
{
    use ModelHelpers;
    use Mappeable;

    protected $container = [];

    private $attribute_map = [
        'type' => 'type',
        'must_redirect' => 'mustRedirect',
        'redirect_url' => 'redirectUrl',
        'complete_url' => 'completeUrl',
    ];

    private $attribute_type = [];

    public function __construct(array $data = null)
    {
        $this->mapAttributes($data);
    }

    /**
     * Get payment status: CONFIRM, CHALLENGE, FRICTIONLESS_CHALLENGE, COMPLETE
     * @return string
     */
    public function getType(): string
    {
        return $this->container['type'];
    }

    /**
     * If you sould redirect to URL
     * @return bool
     */
    public function getMustRedirect(): bool
    {
        return $this->container['redirect_url'] || $this->container['complete_url'];
        //return $this->container['must_redirect'] ? true : false;
    }

    /**
     * URL redirection to complete payment
     * @return null|string
     */
    public function getRedirectUrl(): ?string
    {
        return $this->container['redirect_url'] ?: null;
    }

    /**
     * URL redirection for completed payments
     * @return null|string
     */
    public function getCompleteUrl(): ?string
    {
        return $this->container['complete_url'] ?: null;
    }
}
