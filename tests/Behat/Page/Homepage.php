<?php

declare(strict_types=1);

namespace Tests\Nedac\SyliusOrderNowPlugin\Behat\Page;

final class Homepage extends AbstractOrderNowButtonPage implements HomepageInterface
{
    public function getRouteName(): string
    {
        return 'sylius_shop_homepage';
    }
}
