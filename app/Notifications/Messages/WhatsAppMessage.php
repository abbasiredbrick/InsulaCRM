<?php

namespace App\Notifications\Messages;

/**
 * A WhatsApp template message: a pre-approved template name + language, an
 * ordered list of body parameters, and an optional dynamic URL-button value.
 */
class WhatsAppMessage
{
    protected array $body = [];

    protected ?string $buttonParameter = null;

    public function __construct(
        protected string $template,
        protected string $language = 'en_US',
    ) {}

    public static function template(string $name, string $language = 'en_US'): static
    {
        return new static($name, $language);
    }

    /**
     * Body parameter values, in the order the template's {{1}}, {{2}}… expect.
     *
     * @param  array<int, string>  $parameters
     */
    public function body(array $parameters): static
    {
        $this->body = array_values($parameters);

        return $this;
    }

    /**
     * The value injected into the template's dynamic URL button ({{1}}), if it
     * has one.
     */
    public function button(string $parameter): static
    {
        $this->buttonParameter = $parameter;

        return $this;
    }

    public function templateName(): string
    {
        return $this->template;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function bodyParameters(): array
    {
        return $this->body;
    }

    public function buttonParameter(): ?string
    {
        return $this->buttonParameter;
    }
}
