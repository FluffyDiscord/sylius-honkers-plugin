<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tool\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class OrderStatusArguments
{
    private const NON_ALPHANUMERIC_PATTERN = '/[^A-Za-z0-9]/';

    private readonly string $normalizedOrderNumber;

    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 32)]
        public readonly string $orderNumber = '',
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Email(normalizer: 'trim')]
        public readonly string $email = '',
    ) {
        $this->normalizedOrderNumber = (string) preg_replace(self::NON_ALPHANUMERIC_PATTERN, '', $orderNumber);
    }

    public function getTrimmedOrderNumber(): string
    {
        return trim($this->orderNumber);
    }

    public function getNormalizedOrderNumber(): string
    {
        return $this->normalizedOrderNumber;
    }

    public function getTrimmedEmail(): string
    {
        return trim($this->email);
    }
}
