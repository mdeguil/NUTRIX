<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AuthControllerTest extends WebTestCase
{
    public function testRegisterLoginAndAccessProtectedEndpoint(): void
    {
        $client = static::createClient();
        $username = 'test_' . uniqid();

        $client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => $username,
            'password' => 'password123',
            'role' => 'ROLE_OCCUPANT',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $registerData = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($username, $registerData['username']);
        $this->assertContains('ROLE_OCCUPANT', $registerData['roles']);

        $client->request('POST', '/api/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => $username,
            'password' => 'password123',
        ]));

        $this->assertResponseIsSuccessful();
        $loginData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $loginData);

        $client->request('GET', '/api/me');
        $this->assertResponseStatusCodeSame(401);

        $client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $loginData['token'],
        ]);
        $this->assertResponseIsSuccessful();
        $meData = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($username, $meData['username']);
    }

    public function testRegisterRejectsAdminRole(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => 'wannabe_admin_' . uniqid(),
            'password' => 'password123',
            'role' => 'ROLE_ADMIN',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testRegisterRejectsDuplicateUsername(): void
    {
        $client = static::createClient();
        $username = 'dup_' . uniqid();

        $payload = json_encode([
            'username' => $username,
            'password' => 'password123',
            'role' => 'ROLE_OCCUPANT',
        ]);

        $client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        $this->assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        $this->assertResponseStatusCodeSame(409);
    }
}
