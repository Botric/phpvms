<?php

namespace Tests;

use App\Models\Acars;
use App\Models\Aircraft;
use App\Models\Bid;
use App\Models\Enums\AcarsType;
use App\Models\Enums\PirepState;
use App\Models\Enums\UserState;
use App\Models\Flight;
use App\Models\Navdata;
use App\Models\Pirep;
use App\Models\User;
use App\Notifications\Messages\PirepAccepted;
use App\Notifications\Messages\PirepSubmitted;
use App\Repositories\SettingRepository;
use App\Services\AircraftService;
use App\Services\BidService;
use App\Services\FlightService;
use App\Services\PirepService;
use App\Services\UserService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

class PIREPTest extends TestCase
{
    /** @var PirepService */
    protected $pirepSvc;

    /** @var SettingRepository */
    protected $settingsRepo;

    public function setUp(): void
    {
        parent::setUp();
        $this->addData('base');
        $this->addData('fleet');

        $this->pirepSvc = app(PirepService::class);
        $this->settingsRepo = app(SettingRepository::class);
    }

    protected function createNewRoute()
    {
        $route = [];
        $navpoints = factory(Navdata::class, 5)->create();
        foreach ($navpoints as $point) {
            $route[] = $point->id;
        }

        return $route;
    }

    protected function getAcarsRoute($pirep)
    {
        $saved_route = [];
        $route_points = Acars::where(
            ['pirep_id' => $pirep->id, 'type' => AcarsType::ROUTE]
        )->orderBy('order', 'asc')->get();

        foreach ($route_points as $point) {
            $saved_route[] = $point->name;
        }

        return $saved_route;
    }

    /**
     * @throws Exception
     */
    public function testAddPirep()
    {
        $user = factory(User::class)->create();

        $route = $this->createNewRoute();
        $pirep = factory(Pirep::class)->create([
            'user_id' => $user->id,
            'route'   => implode(' ', $route),
        ]);

        $pirep = $this->pirepSvc->create($pirep, []);

        try {
            $this->pirepSvc->saveRoute($pirep);
        } catch (Exception $e) {
            throw $e;
        }

        /*
         * Check the initial state info
         */
        $this->assertEquals($pirep->state, PirepState::PENDING);

        /**
         * Now set the PIREP state to ACCEPTED
         */
        $new_pirep_count = $pirep->user->flights + 1;
        $new_flight_time = $pirep->user->flight_time + $pirep->flight_time;

        $this->pirepSvc->changeState($pirep, PirepState::ACCEPTED);
        $this->assertEquals($new_pirep_count, $pirep->pilot->flights);
        $this->assertEquals($new_flight_time, $pirep->pilot->flight_time);
        $this->assertEquals($pirep->arr_airport_id, $pirep->pilot->curr_airport_id);

        // Check the location of the current aircraft
        $this->assertEquals($pirep->aircraft->airport_id, $pirep->arr_airport_id);

        // Also check via API:
        $this->get('/api/fleet/aircraft/'.$pirep->aircraft_id, [], $user)
             ->assertJson(['data' => ['airport_id' => $pirep->arr_airport_id]]);

        // Make sure a notification was sent out to both the user and the admin(s)
        Notification::assertSentTo([$user], PirepAccepted::class);

        // Try cancelling it
        $uri = '/api/pireps/'.$pirep->id.'/cancel';
        $response = $this->put($uri, [], [], $user);
        $response->assertStatus(400);

        /**
         * Now go from ACCEPTED to REJECTED
         */
        $new_pirep_count = $pirep->pilot->flights - 1;
        $new_flight_time = $pirep->pilot->flight_time - $pirep->flight_time;
        $this->pirepSvc->changeState($pirep, PirepState::REJECTED);
        $this->assertEquals($new_pirep_count, $pirep->pilot->flights);
        $this->assertEquals($new_flight_time, $pirep->pilot->flight_time);
        $this->assertEquals($pirep->arr_airport_id, $pirep->pilot->curr_airport_id);

        /**
         * Check the ACARS table
         */
        $saved_route = $this->getAcarsRoute($pirep);
        $this->assertEquals($route, $saved_route);

        /**
         * Recreate the route with new options points. Make sure that the
         * old route is erased from the ACARS table and then recreated
         */
        $route = $this->createNewRoute();
        $pirep->route = implode(' ', $route);
        $pirep->save();

        // this should delete the old route from the acars table
        $this->pirepSvc->saveRoute($pirep);

        $saved_route = $this->getAcarsRoute($pirep);
        $this->assertEquals($route, $saved_route);
    }

    /**
     * Make sure the unit conversions look to be proper
     */
    public function testUnitFields()
    {
        $pirep = $this->createPirep();
        $pirep->save();

        $uri = '/api/pireps/'.$pirep->id;

        $response = $this->get($uri);
        $body = $response->json('data');

        // Check that it has the fuel units
        $this->assertHasKeys($body['fuel_used'], ['lbs', 'kg']);
        $this->assertEquals($pirep->fuel_used, $body['fuel_used']['lbs']);

        // Check that it has the distance units
        $this->assertHasKeys($body['distance'], ['km', 'nmi', 'mi']);
        $this->assertEquals($pirep->distance, $body['distance']['nmi']);

        // Check the planned_distance field
        $this->assertHasKeys($body['planned_distance'], ['km', 'nmi', 'mi']);
        $this->assertEquals($pirep->planned_distance, $body['planned_distance']['nmi']);
    }

    public function testGetUserPireps()
    {
        $this->user = factory(User::class)->create();
        $pirep_done = factory(Pirep::class)->create([
            'user_id' => $this->user->id,
            'state'   => PirepState::ACCEPTED,
        ]);

        $pirep_in_progress = factory(Pirep::class)->create([
            'user_id' => $this->user->id,
            'state'   => PirepState::IN_PROGRESS,
        ]);

        $pirep_cancelled = factory(Pirep::class)->create([
            'user_id' => $this->user->id,
            'state'   => PirepState::CANCELLED,
        ]);

        $pireps = $this->get('/api/user/pireps')
                    ->assertStatus(200)
                    ->json();

        $pirep_ids = collect($pireps['data'])->pluck('id');

        $this->assertTrue($pirep_ids->contains($pirep_done->id));
        $this->assertTrue($pirep_ids->contains($pirep_in_progress->id));
        $this->assertFalse($pirep_ids->contains($pirep_cancelled->id));

        // Get only status
        $pireps = $this->get('/api/user/pireps?state='.PirepState::IN_PROGRESS)
            ->assertStatus(200)
            ->json();

        $pirep_ids = collect($pireps['data'])->pluck('id');
        $this->assertTrue($pirep_ids->contains($pirep_in_progress->id));
        $this->assertFalse($pirep_ids->contains($pirep_done->id));
        $this->assertFalse($pirep_ids->contains($pirep_cancelled->id));
    }

    /**
     * Make sure that a notification has been sent out to admins when a PIREP is submitted
     *
     * @throws \Exception
     */
    public function testPirepNotifications()
    {
        /** @var User $user */
        $user = factory(User::class)->create([
            'flights'     => 0,
            'flight_time' => 0,
            'rank_id'     => 1,
        ]);

        $admin = factory(User::class)->create();

        /** @var UserService $userSvc */
        $userSvc = app(UserService::class);
        $userSvc->addUserToRole($admin, 'admin');

        $pirep = factory(Pirep::class)->create([
            'airline_id' => 1,
            'user_id'    => $user->id,
        ]);

        $this->pirepSvc->create($pirep);
        $this->pirepSvc->submit($pirep);

        // Make sure a notification was sent out to the admin
        Notification::assertSentTo([$admin], PirepSubmitted::class);
    }

    /**
     * check the stats/ranks, etc have incremented properly
     */
    public function testPilotStatsIncr()
    {
        $this->updateSetting('pilots.count_transfer_hours', false);

        $user = factory(User::class)->create([
            'flights'     => 0,
            'flight_time' => 0,
            'rank_id'     => 1,
        ]);

        // Submit two PIREPs
        $pireps = factory(Pirep::class, 2)->create([
            'airline_id'  => $user->airline_id,
            'aircraft_id' => 1,
            'user_id'     => $user->id,
            // 360min == 6 hours, rank should bump up
            'flight_time' => 360,
        ]);

        $aircraft = Aircraft::find(1);
        $flight_time_initial = $aircraft->flight_time;

        foreach ($pireps as $pirep) {
            $this->pirepSvc->create($pirep);
            $this->pirepSvc->accept($pirep);
        }

        $pilot = User::find($user->id);
        $last_pirep = Pirep::where('id', $pilot->last_pirep_id)->first();

        // Make sure rank went up
        $this->assertGreaterThan($user->rank_id, $pilot->rank_id);
        $this->assertEquals($last_pirep->arr_airport_id, $pilot->curr_airport_id);
        $this->assertEquals(2, $pilot->flights);

        $aircraft = Aircraft::find(1);
        $after_time = $flight_time_initial + 720;
        $this->assertEquals($after_time, $aircraft->flight_time);

        //
        // Submit another PIREP, adding another 6 hours
        // it should automatically be accepted
        //
        $pirep = factory(Pirep::class)->create([
            'airline_id' => 1,
            'user_id'    => $user->id,
            // 120min == 2 hours, currently at 9 hours
            // Rank bumps up at 10 hours
            'flight_time' => 120,
        ]);

        // Pilot should be at rank 2, where accept should be automatic
        $this->pirepSvc->create($pirep);
        $this->pirepSvc->submit($pirep);

        $pilot->refresh();

        $this->assertEquals(3, $pilot->flights);

        $latest_pirep = Pirep::where('id', $pilot->last_pirep_id)->first();

        // Make sure PIREP was auto updated
        $this->assertEquals(PirepState::ACCEPTED, $latest_pirep->state);

        // Make sure latest PIREP was updated
        $this->assertNotEquals($last_pirep->id, $latest_pirep->id);
    }

    /**
     * check the stats/ranks, etc have incremented properly
     */
    public function testPilotStatsIncrWithTransferHours()
    {
        $this->updateSetting('pilots.count_transfer_hours', true);

        $user = factory(User::class)->create([
            'flights'       => 0,
            'flight_time'   => 0,
            'transfer_time' => 720,
            'rank_id'       => 1,
        ]);

        // Submit two PIREPs
        // 1 hour flight times, but the rank should bump up because of the transfer hours
        $pireps = factory(Pirep::class, 2)->create([
            'airline_id'  => $user->airline_id,
            'aircraft_id' => 1,
            'user_id'     => $user->id,
            'flight_time' => 60,
        ]);

        foreach ($pireps as $pirep) {
            $this->pirepSvc->create($pirep);
            $this->pirepSvc->accept($pirep);
        }

        $pilot = User::find($user->id);
        $last_pirep = $pilot->last_pirep;
        $this->assertEquals($pilot->last_pirep_id, $last_pirep->id);

        // Make sure rank went up
        $this->assertGreaterThan($user->rank_id, $pilot->rank_id);

        // Check the aircraft
        $aircraft = Aircraft::where('id', 1)->first();
        $this->assertEquals(120, $aircraft->flight_time);

        // Reset the aircraft flight time
        $aircraft->flight_time = 10;
        $aircraft->save();

        // Recalculate the status
        /** @var AircraftService $aircraftSvc */
        $aircraftSvc = app(AircraftService::class);
        $aircraftSvc->recalculateStats();

        $aircraft = Aircraft::where('id', 1)->first();
        $this->assertEquals(120, $aircraft->flight_time);
    }

    /**
     * When a PIREP is filed by a user on leave, make sure they flip from leave to active
     * It doesn't matter if the PIREP is accepted or rejected
     */
    public function testPilotStatusChange()
    {
        /** @var \App\Models\User $user */
        $user = factory(User::class)->create([
            'state' => UserState::ON_LEAVE,
        ]);

        // Submit two PIREPs
        // 1 hour flight times, but the rank should bump up because of the transfer hours
        $pirep = factory(Pirep::class)->create([
            'airline_id' => $user->airline_id,
            'user_id'    => $user->id,
        ]);

        $this->pirepSvc->create($pirep);
        $this->pirepSvc->file($pirep);

        $user = User::find($user->id);
        $this->assertEquals(UserState::ACTIVE, $user->state);
    }

    /**
     * Find and check for any duplicate PIREPs by a user
     */
    public function testDuplicatePireps()
    {
        $user = factory(User::class)->create();
        $pirep = factory(Pirep::class)->create([
            'user_id' => $user->id,
        ]);

        // This should find itself...
        $dupe_pirep = $this->pirepSvc->findDuplicate($pirep);
        $this->assertNotFalse($dupe_pirep);
        $this->assertEquals($pirep->id, $dupe_pirep->id);

        /**
         * Create a PIREP outside of the check time interval
         */
        $minutes = setting('pireps.duplicate_check_time') + 1;
        $pirep = factory(Pirep::class)->create([
            'created_at' => Carbon::now()->subMinutes($minutes)->toDateTimeString(),
        ]);

        // This should find itself...
        $dupe_pirep = $this->pirepSvc->findDuplicate($pirep);
        $this->assertFalse($dupe_pirep);
    }

    public function testCancelViaAPI()
    {
        $pirep = $this->createPirep()->toArray();

        $uri = '/api/pireps/prefile';
        $response = $this->post($uri, $pirep);
        $pirep_id = $response->json()['data']['id'];

        $uri = '/api/pireps/'.$pirep_id.'/acars/position';
        $acars = factory(Acars::class)->make()->toArray();
        $response = $this->post($uri, [
            'positions' => [$acars],
        ]);

        $response->assertStatus(200);

        // Cancel it
        $uri = '/api/pireps/'.$pirep_id.'/cancel';
        $response = $this->delete($uri, $acars);
        $response->assertStatus(200);

        // Should get a 400 when posting an ACARS update
        $uri = '/api/pireps/'.$pirep_id.'/acars/position';
        $acars = factory(Acars::class)->make()->toArray();

        $response = $this->post($uri, $acars);
        $response->assertStatus(400);
    }

    /**
     * When a PIREP is accepted, ensure that the bid is removed
     */
    public function testPirepBidRemoved()
    {
        $bidSvc = app(BidService::class);
        $flightSvc = app(FlightService::class);
        $this->settingsRepo->store('pireps.remove_bid_on_accept', true);

        $user = factory(User::class)->create([
            'flight_time' => 0,
        ]);

        $flight = factory(Flight::class)->create([
            'route_code' => null,
            'route_leg'  => null,
        ]);

        $bidSvc->addBid($flight, $user);

        $pirep = factory(Pirep::class)->create([
            'user_id'       => $user->id,
            'airline_id'    => $flight->airline_id,
            'flight_id'     => $flight->id,
            'flight_number' => $flight->flight_number,
        ]);

        $pirep = $this->pirepSvc->create($pirep, []);
        $this->pirepSvc->changeState($pirep, PirepState::ACCEPTED);

        $user_bid = Bid::where([
            'user_id'   => $user->id,
            'flight_id' => $flight->id,
        ])->first();

        $this->assertNull($user_bid);
    }
}
