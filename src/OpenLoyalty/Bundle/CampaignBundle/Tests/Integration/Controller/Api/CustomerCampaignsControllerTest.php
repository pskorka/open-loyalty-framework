<?php

namespace OpenLoyalty\Bundle\CampaignBundle\Tests\Integration\Controller\Api;

use OpenLoyalty\Bundle\CampaignBundle\DataFixtures\ORM\LoadCampaignData;
use OpenLoyalty\Bundle\CoreBundle\Tests\Integration\BaseApiTest;
use OpenLoyalty\Bundle\UserBundle\DataFixtures\ORM\LoadUserData;
use OpenLoyalty\Component\Account\Domain\CustomerId;
use OpenLoyalty\Component\Account\Domain\ReadModel\AccountDetails;
use OpenLoyalty\Component\Campaign\Domain\Campaign;
use OpenLoyalty\Component\Campaign\Domain\CampaignRepository;
use OpenLoyalty\Component\Customer\Domain\CampaignId as CustomerCampaignId;
use OpenLoyalty\Component\Customer\Domain\Model\CampaignPurchase;
use OpenLoyalty\Component\Customer\Domain\Model\Coupon;
use OpenLoyalty\Component\Customer\Domain\ReadModel\CustomerDetails;
use OpenLoyalty\Component\Customer\Domain\ReadModel\CustomerDetailsRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class CustomerCampaignsControllerTest.
 */
class CustomerCampaignsControllerTest extends BaseApiTest
{
    /**
     * @var CampaignRepository
     */
    protected $campaignRepository;

    /**
     * @var CustomerDetailsRepository
     */
    private $customerDetailsRepository;

    protected function setUp()
    {
        parent::setUp();

        static::bootKernel();
        $this->campaignRepository = static::$kernel->getContainer()->get('oloy.campaign.repository');
        $this->customerDetailsRepository = static::$kernel->getContainer()->get('oloy.user.read_model.repository.customer_details');
    }

    /**
     * @test
     */
    public function it_allows_to_buy_a_campaign()
    {
        static::bootKernel();
        $customerDetailsBefore = $this->getCustomerDetails(LoadUserData::USER_USERNAME);
        $accountBefore = $this->getCustomerAccount(new CustomerId($customerDetailsBefore->getCustomerId()->__toString()));

        $client = $this->createAuthenticatedClient(LoadUserData::USER_USERNAME, LoadUserData::USER_PASSWORD, 'customer');
        $client->request(
            'POST',
            '/api/customer/campaign/'.LoadCampaignData::CAMPAIGN_ID.'/buy'
        );

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->assertEquals(200, $response->getStatusCode(), 'Response should have status 200');
        $this->assertArrayHasKey('coupon', $data);
        $customerDetails = $this->getCustomerDetails(LoadUserData::USER_USERNAME);
        $this->assertInstanceOf(CustomerDetails::class, $customerDetails);
        $campaigns = $customerDetails->getCampaignPurchases();
        $found = false;
        foreach ($campaigns as $campaignPurchase) {
            if ($campaignPurchase->getCampaignId()->__toString() == LoadCampaignData::CAMPAIGN_ID) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Customer should have campaign purchase with campaign id = '.LoadCampaignData::CAMPAIGN_ID);

        $accountAfter = $this->getCustomerAccount(new CustomerId($customerDetails->getCustomerId()->__toString()));
        $this->assertTrue(
            ($accountBefore ? $accountBefore->getAvailableAmount() : 0) - 10 == ($accountAfter ? $accountAfter->getAvailableAmount() : 0),
            'Available points after campaign is bought should be '.(($accountBefore ? $accountBefore->getAvailableAmount() : 0) - 10)
            .', but it is '.($accountAfter ? $accountAfter->getAvailableAmount() : 0)
        );
    }

    /**
     * @test
     */
    public function it_returns_serialized_response_with_proper_fields()
    {
        static::bootKernel();
        $client = $this->createAuthenticatedClient(LoadUserData::USER_USERNAME, LoadUserData::USER_PASSWORD, 'customer');
        $client->request(
            'GET',
            '/api/customer/campaign/bought'
        );

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->assertEquals(200, $response->getStatusCode(), 'Response should have status 200');
        $this->assertArrayHasKey('campaigns', $data);
        $campaigns = $data['campaigns'];
        $this->assertGreaterThan(0, count($campaigns));
        $campaign = reset($campaigns);
        $this->assertArrayHasKey('purchaseAt', $campaign, 'Missing purchaseAt data');
        $this->assertArrayHasKey('costInPoints', $campaign, 'Missing costInPoints data');
        $this->assertArrayHasKey('campaignId', $campaign, 'Missing campaignID data');
        $this->assertInternalType('string', $campaign['campaignId'], 'Wrong campaignId type');
        $this->assertArrayHasKey('used', $campaign, 'Missing used data');
        $this->assertArrayHasKey('coupon', $campaign, 'Missing coupon data');
        $coupon = $campaign['coupon'];
        $this->assertArrayHasKey('code', $coupon, 'Missign coupon code value');
    }

    /**
     * @test
     */
    public function it_returns_serialized_response_with_proper_fields_and_includes_details()
    {
        static::bootKernel();
        $client = $this->createAuthenticatedClient(LoadUserData::USER_USERNAME, LoadUserData::USER_PASSWORD, 'customer');
        $client->request(
            'GET',
            '/api/customer/campaign/bought',
            [
                'includeDetails' => 1,
            ]
        );

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->assertEquals(200, $response->getStatusCode(), 'Response should have status 200');
        $this->assertArrayHasKey('campaigns', $data);
        $campaigns = $data['campaigns'];
        $this->assertGreaterThan(0, count($campaigns), 'No bought campaigns');
        $campaign = reset($campaigns);
        $this->assertArrayHasKey('campaign', $campaign, 'No campaigns details');
        $campaignDetails = $campaign['campaign'];
        $this->assertArrayHasKey('campaignId', $campaignDetails, 'Campaign details has no id');
    }

    /**
     * @test
     * @dataProvider sortParamsProvider
     */
    public function it_returns_available_campaigns_list_sorted($field, $direction, $oppositeDirection)
    {
        $client = $this->createAuthenticatedClient(LoadUserData::TEST_USERNAME, LoadUserData::TEST_PASSWORD, 'customer');
        $client->request(
            'GET',
            sprintf('/api/customer/campaign/available?sort=%s&direction=%s', $field, $direction)
        );
        $sortedResponse = $client->getResponse();
        $sortedData = json_decode($sortedResponse->getContent(), true);
        $this->assertEquals(200, $sortedResponse->getStatusCode(), 'Response should have status 200');

        $this->assertArrayHasKey('campaigns', $sortedData);

        if ($sortedData['total'] < 2) {
            return;
        }

        $firstElementSorted = reset($sortedData['campaigns']);
        $sortedSize = count($sortedData['campaigns']);

        $client = $this->createAuthenticatedClient(LoadUserData::TEST_USERNAME, LoadUserData::TEST_PASSWORD, 'customer');
        $client->request(
            'GET',
            sprintf('/api/customer/campaign/available?sort=%s&direction=%s', $direction, $oppositeDirection)
        );
        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);
        $firstElement = reset($data['campaigns']);
        $size = count($data['campaigns']);

        $this->assertNotEquals($firstElement['campaignId'], $firstElementSorted['campaignId']);
        $this->assertEquals($size, $sortedSize);
    }

    /**
     * @return array
     */
    public function sortParamsProvider()
    {
        return [
            ['campaignId', 'asc', 'desc'],
            ['name', 'asc', 'desc'],
            ['description', 'asc', 'desc'],
            ['reward', 'asc', 'desc'],
            ['active', 'asc', 'desc'],
            ['costInPoints', 'asc', 'desc'],
            ['hasPhoto', 'asc', 'desc'],
            ['usageLeft', 'asc', 'desc'],
        ];
    }

    /**
     * @param CustomerId $customerId
     *
     * @return AccountDetails|null
     */
    protected function getCustomerAccount(CustomerId $customerId)
    {
        $accountDetailsRepository = static::$kernel->getContainer()->get('oloy.points.account.repository.account_details');
        $accounts = $accountDetailsRepository->findBy(['customerId' => $customerId->__toString()]);
        if (count($accounts) == 0) {
            return;
        }

        return reset($accounts);
    }

    /**
     * @param $email
     *
     * @return CustomerDetails
     */
    protected function getCustomerDetails($email)
    {
        $customerDetails = $this->customerDetailsRepository->findBy(['email' => $email]);
        /** @var CustomerDetails $customerDetails */
        $customerDetails = reset($customerDetails);

        return $customerDetails;
    }

    /**
     * @test
     */
    public function it_change_customer_coupon_to_used()
    {
        $customerDetails = $this->getCustomerDetails(LoadUserData::USER2_USERNAME);
        $couponCode = Uuid::uuid4()->toString();
        $customerDetails->addCampaignPurchase(
            new CampaignPurchase(
                new \DateTime(),
                0,
                new CustomerCampaignId(LoadCampaignData::CAMPAIGN_ID),
                new Coupon($couponCode),
                Campaign::REWARD_TYPE_DISCOUNT_CODE
            )
        );

        $this->customerDetailsRepository->save($customerDetails);

        $client = $this->createAuthenticatedClient(LoadUserData::USER2_USERNAME, LoadUserData::USER2_PASSWORD, 'customer');
        $client->request(
            'POST',
            sprintf(
                '/api/customer/campaign/%s/coupon/%s',
                LoadCampaignData::CAMPAIGN_ID,
                $couponCode
            ),
            [
                'used' => true,
            ]
        );

        $response = $client->getResponse();

        $customerDetails = $this->getCustomerDetails(LoadUserData::USER2_USERNAME);
        $campaigns = $customerDetails->getCampaignPurchases();
        $campaignPurchase = null;

        /** @var CampaignPurchase $campaign */
        foreach ($campaigns as $campaign) {
            if ($campaign->getCoupon()->getCode() === $couponCode) {
                $campaignPurchase = $campaign;
            }
        }

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode(), 'Response should have status 200');
        $this->assertNotNull($campaignPurchase);
        $this->assertInstanceOf(CampaignPurchase::class, $campaignPurchase);
        $this->assertTrue($campaignPurchase->isUsed());
    }

    /**
     * @test
     */
    public function it_change_multiple_customer_coupons_to_used()
    {
        $customerDetails = $this->getCustomerDetails(LoadUserData::USER2_USERNAME);
        $couponCode = Uuid::uuid4()->toString();
        $customerDetails->addCampaignPurchase(
            new CampaignPurchase(
                new \DateTime(),
                0,
                new CustomerCampaignId(LoadCampaignData::CAMPAIGN_ID),
                new Coupon($couponCode),
                Campaign::REWARD_TYPE_DISCOUNT_CODE
            )
        );

        $this->customerDetailsRepository->save($customerDetails);

        $client = $this->createAuthenticatedClient(LoadUserData::USER2_USERNAME, LoadUserData::USER2_PASSWORD, 'customer');
        $client->request(
            'POST',
            '/api/customer/campaign/coupons/mark_as_used',
            [
                'coupons' => [
                        [
                            'campaignId' => LoadCampaignData::CAMPAIGN_ID,
                            'code' => $couponCode,
                            'used' => true,
                        ],
                    ],
            ]
        );

        $response = $client->getResponse();

        $customerDetails = $this->getCustomerDetails(LoadUserData::USER2_USERNAME);
        $campaigns = $customerDetails->getCampaignPurchases();
        $campaignPurchase = null;

        /** @var CampaignPurchase $campaign */
        foreach ($campaigns as $campaign) {
            if ($campaign->getCoupon()->getCode() === $couponCode) {
                $campaignPurchase = $campaign;
            }
        }

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode(), 'Response should have status 200');
        $this->assertNotNull($campaignPurchase);
        $this->assertInstanceOf(CampaignPurchase::class, $campaignPurchase);
        $this->assertTrue($campaignPurchase->isUsed());
    }
}
