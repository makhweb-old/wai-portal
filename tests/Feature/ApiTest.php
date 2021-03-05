<?php

namespace Tests\Feature;

use App\Enums\UserPermission;
use App\Enums\UserStatus;
use App\Enums\WebsiteAccessType;
use App\Enums\WebsiteStatus;
use App\Enums\WebsiteType;
use App\Models\Credential;
use App\Models\PublicAdministration;
use App\Models\User;
use App\Models\Website;
use Carbon\Carbon;
use CodiceFiscale\Calculator;
use CodiceFiscale\Subject;
use Faker\Factory;
use GuzzleHttp\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

/**
 * Public Administration analytics dashboard controller tests.
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The user.
     *
     * @var User the user
     */
    protected $user;

    /**
     * The Kong credential.
     *
     * @var the Kong credential
     */
    private $credential;

    /**
     * The public administration.
     *
     * @var PublicAdministration the public administration
     */
    private $publicAdministration;

    /**
     * The website.
     *
     * @var Website the website
     */
    private $website;

    /**
     * Fake data generator.
     *
     * @var Generator the generator
     */
    private $faker;

    private $client;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->client = new Client(['base_uri' => config('app.url')]);

        $this->publicAdministration = factory(PublicAdministration::class)
            ->state('active')
            ->create();

        $this->user = factory(User::class)->state('active')->create();
        $this->publicAdministration->users()->sync([$this->user->id => ['user_email' => $this->user->email, 'user_status' => UserStatus::ACTIVE]]);

        $this->website = factory(Website::class)->create([
            'public_administration_id' => $this->publicAdministration->id,
            'status' => WebsiteStatus::ACTIVE,
            'type' => WebsiteType::INSTITUTIONAL,
        ]);

        $this->credential = factory(Credential::class)->create([
            'public_administration_id' => $this->publicAdministration->id,
        ]);

        $this->faker = Factory::create();

        Bouncer::dontCache();
    }

    /**
     * Test API request fails due to missing consumer ID header.
     */
    public function testApiErrorNoConsumerId(): void
    {
        $response = $this->json('GET', route('api.sites.show'), [], [
            'X-Consumer-Custom-Id' => '"{\"type\":\"admin\",\"siteId\":[]}"',
        ]);

        $this->assertEquals(500, $response->getStatusCode());
    }

    public function testApiErrorNoCustomId(): void
    {
        $response = $this->json('GET', route('api.sites.show'), [], [
            'X-Consumer-Id' => 'f8846ee4-031d-4a1b-88d5-08efc0d44eb2',
        ]);

        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * Test API request fails as Credential type is not "admin".
     */
    public function testApiErrorAnalyticsType(): void
    {
        $response = $this->json('GET', route('api.sites.show'), [], [
            'X-Consumer-Custom-Id' => '"{\"type\":\"analytics\",\"siteId\":[]}"',
            'X-Consumer-Id' => 'f8846ee4-031d-4a1b-88d5-08efc0d44eb2',
        ]);

        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Test API website list, should pass.
     */
    public function testWebsiteList(): void
    {
        $response = $this->json('GET', route('api.sites.show'), [], [
            'X-Consumer-Custom-Id' => '{"type":"admin","siteId":[]}',
            'X-Consumer-Id' => $this->credential->consumer_id,
            'Accept' => 'application/json',
        ]);

        $response
            ->assertStatus(200)
            ->assertJson([[
                'id' => $this->website->id,
                'name' => $this->website->name,
                'url' => $this->website->url,
                'analytics_id' => $this->website->analytics_id,
                'slug' => $this->website->slug,
            ]]);
    }

    /**
     * Test API should pass.
     */
    public function testWebsiteCreate(): void
    {
        $domain_name = 'https://' . $this->faker->domainName;
        $slug = Str::slug($domain_name);
        $name = $this->faker->words(5, true);

        $response = $this->json('POST', route('api.sites.add'), [
            'website_name' => $name,
            'url' => $domain_name,
            'type' => 1,
        ], [
            'X-Consumer-Custom-Id' => '{"type":"admin","siteId":[]}',
            'X-Consumer-Id' => $this->credential->consumer_id,
            'Accept' => 'application/json',
        ]);

        $location = config('kong-service.api_url') . str_replace('/api/', '/portal/', route('api.sites.read', ['website' => $slug], false));

        $response
            ->assertStatus(201)
            ->assertHeader('Location', $location)
            ->assertJson([
                'name' => $name,
                'url' => $domain_name,
                'slug' => $slug,
            ]);
    }

    public function testWebsiteRead(): void
    {
        $response = $this->json('GET', route('api.sites.read', ['website' => $this->website->slug]), [], [
            'X-Consumer-Custom-Id' => '{"type":"admin","siteId":[]}',
            'X-Consumer-Id' => $this->credential->consumer_id,
            'Accept' => 'application/json',
        ]);

        $response
            ->assertStatus(200)
            ->assertJson([
                'id' => $this->website->id,
                'name' => $this->website->name,
                'url' => $this->website->url,
                'analytics_id' => $this->website->analytics_id,
                'slug' => $this->website->slug,
            ]);
    }

    public function testWebsiteEdit(): void
    {
        $name = $this->faker->words(5, true);
        $domain = 'https://' . $this->faker->domainName;

        $analyticsId = app()->make('analytics-service')->registerSite($name, $domain, $this->publicAdministration->name);

        $websiteToEdit = factory(Website::class)->make([
            'name' => $name,
            'public_administration_id' => $this->publicAdministration->id,
            'status' => WebsiteStatus::ACTIVE,
            'type' => WebsiteType::INSTITUTIONAL_PLAY,
            'analytics_id' => $analyticsId,
        ]);
        $websiteToEdit->save();

        $newDomain = 'https://' . $this->faker->domainName;
        $newSlug = Str::slug($newDomain);
        $newName = $this->faker->words(5, true);

        $response = $this->json('PATCH', route('api.sites.update', ['website' => $websiteToEdit]), [
            'website_name' => $newName,
            'url' => $newDomain,
            'type' => 3,
            'slug' => $newSlug,
        ], [
            'X-Consumer-Custom-Id' => '{"type":"admin","siteId":[]}',
            'X-Consumer-Id' => $this->credential->consumer_id,
            'Accept' => 'application/json',
        ]);

        $response
            ->assertStatus(200)
            ->assertJson([
                'id' => $websiteToEdit->id,
                'name' => $newName,
                'url' => $newDomain,
                'analytics_id' => $analyticsId,
                'slug' => $newSlug,
            ]);
    }

    /*
        Check if the website is active
    */
    public function testWebsiteCheck(): void
    {
        $name = $this->faker->words(5, true);
        $domain = 'https://' . $this->faker->domainName;
        $analyticsId = app()->make('analytics-service')->registerSite($name, $domain, $this->publicAdministration->name);

        $websiteCheck = factory(Website::class)->make([
            'name' => $name,
            'public_administration_id' => $this->publicAdministration->id,
            'status' => WebsiteStatus::PENDING,
            'type' => WebsiteType::INSTITUTIONAL_PLAY,
            'analytics_id' => $analyticsId,
        ]);
        $websiteCheck->save();

        $response = $this->json('GET', route('api.sites.check', ['website' => $websiteCheck]), [], [
            'X-Consumer-Custom-Id' => '{"type":"admin","siteId":[]}',
            'X-Consumer-Id' => $this->credential->consumer_id,
            'Accept' => 'application/json',
        ]);

        $response
            ->assertStatus(304);
    }

    public function testWebsiteSnippet(): void
    {
        $response = $this->json('GET', route('api.sites.snippet.javascript', ['website' => $this->website]), [], [
            'X-Consumer-Custom-Id' => '{"type":"admin","siteId":[]}',
            'X-Consumer-Id' => $this->credential->consumer_id,
            'Accept' => 'application/json',
        ]);

        $response
            ->assertStatus(200)
            ->assertJson([
                'result' => 'ok',
                'id' => $this->website->slug,
                'name' => $this->website->name,
                ]);
    }

    /**
     * Test API user list.
     */
    public function testUserList(): void
    {
        $response = $this->json('GET', route('api.users'), [], [
            'X-Consumer-Custom-Id' => '{"type":"admin","siteId":[]}',
            'X-Consumer-Id' => $this->credential->consumer_id,
            'Accept' => 'application/json',
        ]);

        $response
            ->assertStatus(200)
            ->assertJson([[
                'id' => $this->user->id,
                'firstName' => $this->user->name,
                'lastName' => $this->user->family_name,
                'codice_fiscale' => $this->user->fiscal_number,
                'email' => $this->publicAdministration->pec,
            ]]);
    }

    public function testUserCreate(): void
    {
        $email = $this->faker->unique()->freeEmail;
        $fiscalNumber = (new Calculator(
                new Subject(
                    [
                        'name' => $this->faker->firstName,
                        'surname' => $this->faker->lastName,
                        'birthDate' => Carbon::createFromDate(rand(1950, 1990), rand(1, 12), rand(1, 30)),
                        'gender' => rand(0, 1) ? Calculator::CHR_MALE : Calculator::CHR_WOMEN,
                        'belfioreCode' => 'H501',
                    ]
                )
            ))->calculate();

        $websiteId = $this->website->id;

        $response = $this->json('POST', route('api.users.store'), [
            'email' => $email,
            'fiscal_number' => $fiscalNumber,
            'permissions' => [
                $websiteId => ['manage-analytics'],
            ],
        ], [
            'X-Consumer-Custom-Id' => '{"type":"admin","siteId":[]}',
            'X-Consumer-Id' => $this->credential->consumer_id,
            'Accept' => 'application/json',
        ]);

        $location = config('kong-service.api_url') . str_replace('/api/', '/portal/', route('api.users.show', ['fn' => $fiscalNumber], false));

        $response
            ->assertStatus(201)
            ->assertHeader('Location', $location)
            ->assertJson([
                'firstName' => '',
                'lastName' => '',
                'codice_fiscale' => $fiscalNumber,
                'email' => $this->publicAdministration->pec,
            ]);
    }

    public function testUserRead(): void
    {
        $response = $this->json('GET', route('api.users.show', ['fn' => $this->user->fiscal_number]), [], [
            'X-Consumer-Custom-Id' => '{"type":"admin","siteId":[]}',
            'X-Consumer-Id' => $this->credential->consumer_id,
            'Accept' => 'application/json',
        ]);

        $response
            ->assertStatus(200)
            ->assertJson([
                'id' => $this->user->id,
                'firstName' => $this->user->name,
                'lastName' => $this->user->family_name,
                'codice_fiscale' => $this->user->fiscal_number,
                'email' => $this->publicAdministration->pec,
            ]);
    }

    public function testUserEdit(): void
    {
        $userToEdit = factory(User::class)->make([
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => Date::now(),
        ]);
        $userToEdit->save();
        $this->publicAdministration->users()->sync([$userToEdit->id => ['user_email' => $userToEdit->email, 'user_status' => UserStatus::ACTIVE]]);

        $userToEdit->registerAnalyticsServiceAccount();
        $analyticsId = app()->make('analytics-service')->registerSite($this->website->name . ' [' . $this->website->type . ']', $this->website->url, $this->publicAdministration->name);
        $this->website->analytics_id = $analyticsId;
        $this->website->save();
        app()->make('analytics-service')->setWebsiteAccess($userToEdit->uuid, WebsiteAccessType::WRITE, $this->website->analytics_id);

        $email = $this->faker->unique()->freeEmail;

        $response = $this->json('PATCH', route('api.users.update', ['fn' => $userToEdit->fiscal_number]), [
            'emailPublicAdministrationUser' => 'new@webanalytics.italia.it',
            'email' => $email,
            'permissions' => [
                $this->website->id => [
                    UserPermission::MANAGE_ANALYTICS,
                    UserPermission::READ_ANALYTICS,
                ],
            ],
        ], [
            'X-Consumer-Custom-Id' => '{"type":"admin","siteId":[]}',
            'X-Consumer-Id' => $this->credential->consumer_id,
            'Accept' => 'application/json',
        ]);

        $response
            /* ->assertStatus(200) */
            ->assertJson([
                'id' => $userToEdit->id,
                'firstName' => $userToEdit->name,
                'lastName' => $userToEdit->family_name,
                'codice_fiscale' => $userToEdit->fiscal_number,
                'email' => $this->publicAdministration->pec,
            ]);
    }

    public function testUserSuspend(): void
    {
        $response = $this->json('GET', route('api.users.suspend', ['fn' => $this->user->fiscal_number]), [], [
            'X-Consumer-Custom-Id' => '{"type":"admin","siteId":[]}',
            'X-Consumer-Id' => $this->credential->consumer_id,
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(200);
    }
}