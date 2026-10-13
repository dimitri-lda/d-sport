<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Application\Ordering\OrderView;
use App\Application\Ordering\PlaceOrder;
use App\Domain\Identity\Entity\User;
use App\Infrastructure\Ordering\DbalOrderReadModel;
use App\Presentation\Http\ApiProblem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(OrderView::class)]
final class OrderViewTest extends TestCase
{
    private const ORDER = [
        'id' => 9,
        'number' => 'SP-1234ABCD',
        'user_id' => 7,
        'items' => [],
    ];

    private DbalOrderReadModel $orders;
    private Security $security;
    private OrderView $view;

    protected function setUp(): void
    {
        $this->orders = $this->createMock(DbalOrderReadModel::class);
        $this->security = $this->createMock(Security::class);
        $this->view = new OrderView($this->orders, $this->security);
    }

    private static function requestWithSession(?string $lastOrder): Request
    {
        $request = Request::create('/api/v1/orders/SP-1234ABCD');
        $session = new Session(new MockArraySessionStorage());
        if (null !== $lastOrder) {
            $session->set(PlaceOrder::SESSION_LAST_ORDER, $lastOrder);
        }
        $request->setSession($session);

        return $request;
    }

    private static function user(int $id): User
    {
        $user = new User('owner@example.test', 'hash', 'Owner');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    public function testGuestReadsOnlyTheirLastOrder(): void
    {
        $this->security->method('getUser')->willReturn(null);
        $this->orders->method('byNumber')->with('SP-1234ABCD')->willReturn(array_merge(self::ORDER, ['user_id' => null]));

        $order = $this->view->byNumberFor(self::requestWithSession('SP-1234ABCD'), 'SP-1234ABCD');

        self::assertSame('SP-1234ABCD', $order['number']);
    }

    public function testGuestWithoutMarkerGets404(): void
    {
        $this->security->method('getUser')->willReturn(null);
        $this->orders->method('byNumber')->willReturn(array_merge(self::ORDER, ['user_id' => null]));

        try {
            $this->view->byNumberFor(self::requestWithSession('SP-DEADBEEF'), 'SP-1234ABCD');
            self::fail('Expected not_found.');
        } catch (ApiProblem $problem) {
            self::assertSame('not_found', $problem->errorCode);
            self::assertSame(404, $problem->status);
        }
    }

    public function testAuthenticatedUserReadsOwnOrderButNotForeignOnes(): void
    {
        $this->security->method('getUser')->willReturn(self::user(7));
        $this->orders->method('byNumber')->willReturn(self::ORDER);

        $order = $this->view->byNumberFor(self::requestWithSession(null), 'SP-1234ABCD');
        self::assertSame(9, $order['id']);
    }

    public function testAuthenticatedUserKeepsGuestOrderMarkerFromSameSession(): void
    {
        // Legacy OR rule: owner OR this session's last_order marker. A guest
        // who registers/logs in after checkout must still see their order.
        $this->security->method('getUser')->willReturn(self::user(8));
        $this->orders->method('byNumber')->willReturn(self::ORDER);

        $order = $this->view->byNumberFor(self::requestWithSession('SP-1234ABCD'), 'SP-1234ABCD');

        self::assertSame(9, $order['id']);
    }

    public function testAuthenticatedUserCannotReadForeignOrderWithoutMarker(): void
    {
        $this->security->method('getUser')->willReturn(self::user(8));
        $this->orders->method('byNumber')->willReturn(self::ORDER);

        try {
            $this->view->byNumberFor(self::requestWithSession('SP-DEADBEEF'), 'SP-1234ABCD');
            self::fail('Expected not_found for a foreign order.');
        } catch (ApiProblem $problem) {
            self::assertSame('not_found', $problem->errorCode);
            self::assertSame(404, $problem->status);
        }
    }

    public function testUnknownNumberIs404(): void
    {
        $this->orders->method('byNumber')->willReturn(null);

        try {
            $this->view->byNumberFor(self::requestWithSession('SP-1234ABCD'), 'SP-1234ABCD');
            self::fail('Expected not_found.');
        } catch (ApiProblem $problem) {
            self::assertSame(404, $problem->status);
        }
    }
}
