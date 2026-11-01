<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ErrorPagesTest extends WebTestCase
{
    public function testMissingPageUsesTheStoreLayout(): void
    {
        // Debug mode shows Symfony's exception page, so production's page needs debug off
        $client = static::createClient(['debug' => false]);

        $crawler = $client->request('GET', '/this-page-does-not-exist');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSelectorTextContains('h1', "We can't find that page");
        $this->assertCount(1, $crawler->filter('header a[href="/cart"]'));
        $this->assertCount(1, $crawler->filter('main a[href="/catalog"]'));
    }
}
