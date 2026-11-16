<?php

namespace Database\Seeders;

use App\Enums\ActivityAction;
use App\Enums\ApprovalStatus;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\DepartmentApiKey;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\User;
use App\Notifications\ReportStatusUpdated;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fills an empty database with realistic demo data for testing and
 * demonstrations: staff accounts, approved citizens who write in English,
 * Sinhala and Tamil, pending and rejected registrations, and about thirty
 * reports spread over six months with full status timelines, votes,
 * notifications and activity log entries.
 *
 * Not part of DatabaseSeeder. Run it on purpose, once, on a database with no
 * citizens or reports:
 *
 *   php artisan db:seed --class=DemoDataSeeder --force
 *
 * Photos are copied from storage/app/demo-photos/<category-slug>/*.jpg and
 * NIC samples from storage/app/demo-photos/nic/sample_<n>_{front,back}.jpg.
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'Civic@2026';

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, array{0: string, 1: float, 2: float}> */
    private const LOCATIONS = [
        'wellawatte' => ['Galle Road, near Wellawatte railway station, Colombo 06', 6.8747, 79.8605],
        'bambalapitiya' => ['Duplication Road, Bambalapitiya, Colombo 04', 6.8892, 79.8567],
        'kollupitiya' => ['Dharmapala Mawatha, Kollupitiya, Colombo 03', 6.9100, 79.8530],
        'havelock' => ['Havelock Road, Havelock Town, Colombo 05', 6.8820, 79.8660],
        'borella' => ['D. S. Senanayake Mawatha, Borella, Colombo 08', 6.9147, 79.8778],
        'dehiwala' => ['Dutugemunu Street, Dehiwala', 6.8524, 79.8700],
        'cinnamon' => ['Stanley Wijesundara Mawatha, Colombo 07', 6.9007, 79.8607],
        'rajagiriya' => ['Parliament Road, Rajagiriya', 6.9090, 79.8950],
        'greenpath' => ['Green Path, Colombo 07', 6.9135, 79.8590],
        'mtlavinia' => ['Hotel Road, Mount Lavinia', 6.8389, 79.8636],
        'moratuwa' => ['Galle Road, opposite Moratuwa bus stand, Moratuwa', 6.7730, 79.8817],
        'battaramulla' => ['Pannipitiya Road, Battaramulla', 6.8990, 79.9180],
        'nugegoda' => ['High Level Road, Nugegoda', 6.8724, 79.8889],
        'maharagama' => ['High Level Road, near Maharagama market, Maharagama', 6.8480, 79.9265],
        'kelaniya' => ['Temple Road, Kelaniya', 6.9553, 79.9220],
        'kadawatha' => ['Kandy Road, Kadawatha town', 7.0012, 79.9530],
        'kiribathgoda' => ['Kandy Road, Kiribathgoda', 6.9786, 79.9297],
        'kotte' => ['Ethul Kotte Road, Kotte', 6.8935, 79.9070],
        'kalubowila' => ['Hospital Road, Kalubowila', 6.8667, 79.8775],
        'dehiwalabeach' => ['Beach Road, Dehiwala', 6.8510, 79.8620],
        'wattala' => ['Negombo Road, Wattala', 6.9897, 79.8917],
        'wasilva' => ['W. A. Silva Mawatha, Wellawatte, Colombo 06', 6.8781, 79.8644],
        'kotahena' => ['Kotahena Street, Kotahena, Colombo 13', 6.9472, 79.8612],
        'jampettah' => ['Jampettah Street, Colombo 13', 6.9440, 79.8640],
        'seastreet' => ['Sea Street, Colombo 11', 6.9389, 79.8497],
        'oldmoor' => ['Old Moor Street, Colombo 12', 6.9409, 79.8560],
        'baseline' => ['Baseline Road, Dematagoda, Colombo 09', 6.9330, 79.8770],
        'marine' => ['Marine Drive, Wellawatte, Colombo 06', 6.8760, 79.8570],
        'colombo12' => ['Wolfendhal Street, Colombo 12', 6.9425, 79.8590],
    ];

    /**
     * Department that handles each category.
     *
     * @var array<string, string>
     */
    private const DEPARTMENT_FOR = [
        'pothole' => 'Roads and Highways',
        'road-damage' => 'Roads and Highways',
        'damaged-sign' => 'Roads and Highways',
        'garbage' => 'Waste Management',
        'flooding' => 'Drainage',
        'blocked-drain' => 'Drainage',
        'broken-street-light' => 'Electrical',
        'fallen-tree' => 'Parks and Recreation',
        'damaged-public-property' => 'Parks and Recreation',
        'other' => 'Water Board Liaison',
    ];

    /**
     * Staff notes posted when work starts and when the issue is fixed.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const NOTES = [
        'pothole' => ['Site inspected. Patching has been scheduled for this week.', 'Pothole filled and the surface compacted. Thank you for reporting it.'],
        'road-damage' => ['Inspection done. Resurfacing of the damaged section has been added to the works schedule.', 'The damaged section has been resurfaced and the road is safe to use.'],
        'damaged-sign' => ['Replacement sign ordered from the depot.', 'New sign installed and secured.'],
        'garbage' => ['Collection crew dispatched. The spot will be added to the regular collection route.', 'Waste cleared and the area cleaned. The area officer will monitor it for illegal dumping.'],
        'flooding' => ['Drainage team on site clearing the outlet and checking the culvert.', 'Culvert cleared and the outlet widened. Water now drains within minutes after rain.'],
        'blocked-drain' => ['Drain cleaning crew assigned. Silt removal starts tomorrow morning.', 'Drain desilted and plastic waste removed along the full length.'],
        'broken-street-light' => ['Replacement lamps ordered. A crew will attend this week.', 'Lamps replaced and tested. All lights on this stretch are working.'],
        'fallen-tree' => ['Parks crew and the electricity board are on site to remove the tree safely.', 'Tree removed and the road reopened.'],
        'damaged-public-property' => ['Area cordoned off for safety. Repair work has been scheduled.', 'Repairs completed and the area reopened to the public.'],
        'other' => ['The Water Board has been notified and a repair team is on the way.', 'Pipe repaired by the Water Board. Please tell us if the leak returns.'],
    ];

    public function run(): void
    {
        if (User::where('role', UserRole::Citizen)->exists() || Report::exists()) {
            throw new RuntimeException('DemoDataSeeder only runs on a database with no citizens or reports.');
        }

        if (! is_dir(storage_path('app/demo-photos'))) {
            throw new RuntimeException('Copy the demo photos to storage/app/demo-photos first.');
        }

        mt_srand(2026);
        $this->call([CategorySeeder::class, DepartmentSeeder::class]);

        $this->seedPanelAccounts();
        $this->seedCitizens();
        $this->seedReports();
        $apiKey = $this->seedApiKey();

        $this->command?->info('Demo data seeded. Department API key (Roads and Highways, read & update): '.$apiKey);
    }

    private function seedPanelAccounts(): void
    {
        $this->users['admin'] = User::where('role', UserRole::Admin)->orderBy('id')->firstOrFail();

        foreach ([
            'staff' => ['Nadeesha Wickramasinghe', 'staff@civicpulse.test'],
            'staff2' => ['Rizwan Farook', 'rizwan.staff@civicpulse.test'],
        ] as $key => [$name, $email]) {
            $this->users[$key] = User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => self::PASSWORD,
                'role' => UserRole::Staff,
                'approval_status' => ApprovalStatus::Approved,
                'approved_at' => now()->subMonths(7),
                'is_active' => true,
            ]);
            $this->users[$key]->forceFill(['created_at' => now()->subMonths(7)])->saveQuietly();
        }
    }

    private function seedCitizens(): void
    {
        $citizens = [
            // key => [name, email, phone, nic, status, joined days ago]
            'dilan' => ['Dilan Fernando', 'citizen.en@civicpulse.test', '0771234501', '199214503456', 'approved', 185],
            'kasun' => ['Kasun Jayasinghe', 'citizen.si@civicpulse.test', '0712345602', '199530102341', 'approved', 180],
            'priya' => ['Priya Rajendran', 'citizen.ta@civicpulse.test', '0763456703', '199826704512', 'approved', 178],
            'ayesha' => ['Ayesha Perera', 'ayesha.perera@example.lk', '0779876504', '199065201234', 'approved', 182],
            'ruwan' => ['Ruwan Dissanayake', 'ruwan.d@example.lk', '0701122305', '198812309876', 'approved', 170],
            'chaminda' => ['Chaminda Silva', 'chaminda.silva@example.lk', '0718899006', '197834501928', 'approved', 175],
            'malini' => ['Malini Kumari', 'malini.k@example.lk', '0754433207', '845671234V', 'approved', 160],
            'tharindu' => ['Tharindu Bandara', 'tharindu.b@example.lk', '0786655408', '200109804567', 'approved', 120],
            'suresh' => ['Suresh Kanagaratnam', 'suresh.k@example.lk', '0777788109', '198021505678', 'approved', 176],
            'fathima' => ['Fathima Rifka', 'fathima.rifka@example.lk', '0765544310', '199670206789', 'approved', 150],
            'sanduni' => ['Sanduni Herath', 'sanduni.h@example.lk', '0714433211', '200275601234', 'pending', 1],
            'imran' => ['Mohamed Imran', 'imran.m@example.lk', '0752211312', '199312307654', 'pending', 0],
            'gayan' => ['Gayan Weerasinghe', 'gayan.w@example.lk', '0703344513', '199145608765', 'rejected', 9],
            'lahiru' => ['Lahiru Gunawardena', 'lahiru.g@example.lk', '0726677814', '198733409871', 'deactivated', 140],
        ];

        $sample = 0;

        foreach ($citizens as $key => [$name, $email, $phone, $nic, $status, $joinedDaysAgo]) {
            $joinedAt = now()->subDays($joinedDaysAgo)->setTime(mt_rand(7, 21), mt_rand(0, 59));
            $sample = $sample % 3 + 1;

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => self::PASSWORD,
                'role' => UserRole::Citizen,
                'phone' => $phone,
                'nic_number' => $nic,
                'nic_photo_front_path' => $this->storeFile("nic/sample_{$sample}_front.jpg", 'nic-photos'),
                'nic_photo_back_path' => $this->storeFile("nic/sample_{$sample}_back.jpg", 'nic-photos'),
                'approval_status' => match ($status) {
                    'pending' => ApprovalStatus::Pending,
                    'rejected' => ApprovalStatus::Rejected,
                    default => ApprovalStatus::Approved,
                },
                'rejection_reason' => $status === 'rejected' ? 'The NIC photos are blurred and the number cannot be read. Please register again with clear photos of both sides.' : null,
                'is_active' => $status !== 'deactivated',
            ]);
            $user->forceFill(['created_at' => $joinedAt, 'updated_at' => $joinedAt])->saveQuietly();

            if ($status === 'pending') {
                $this->users[$key] = $user;

                continue;
            }

            $reviewedAt = $joinedAt->copy()->addHours(mt_rand(3, 30))->min(now()->subHour());
            $reviewer = mt_rand(0, 1) ? $this->users['staff'] : $this->users['admin'];

            if ($status === 'rejected') {
                $this->log(ActivityAction::CitizenRejected, "Rejected {$name}'s registration.", $user, ['reason' => $user->rejection_reason], $reviewer, $reviewedAt);
            } else {
                $user->forceFill(['approved_at' => $reviewedAt, 'approved_by' => $reviewer->id])->saveQuietly();
                $this->log(ActivityAction::CitizenApproved, "Approved {$name}'s registration.", $user, [], $reviewer, $reviewedAt);
            }

            if ($status === 'deactivated') {
                $this->log(ActivityAction::CitizenDeactivated, "Deactivated {$name}'s account.", $user, [], $this->users['admin'], now()->subDays(20));
            }

            $this->users[$key] = $user;
        }
    }

    private function seedReports(): void
    {
        $voters = collect($this->users)->only(['dilan', 'kasun', 'priya', 'ayesha', 'ruwan', 'chaminda', 'malini', 'tharindu', 'suresh', 'fathima'])->values();

        foreach ($this->reports() as $index => [$author, $slug, $status, $daysAgo, $location, $description, $options]) {
            $category = Category::where('slug', $slug)->firstOrFail();
            $department = Department::where('name', self::DEPARTMENT_FOR[$slug])->firstOrFail();
            [$address, $latitude, $longitude] = self::LOCATIONS[$location];
            $citizen = $this->users[$author];
            $status = ReportStatus::from($status);
            $assigned = $status !== ReportStatus::Open || ($options['assigned'] ?? false);

            $createdAt = now()->subDays($daysAgo)->setTime(mt_rand(6, 20), mt_rand(0, 59));
            if ($createdAt->isFuture()) {
                $createdAt = now()->subHours(mt_rand(2, 6));
            }

            $report = Report::create([
                'user_id' => $citizen->id,
                'category_id' => $category->id,
                'department_id' => $assigned ? $department->id : null,
                'title' => Str::limit($description, 60),
                'description' => $description,
                'latitude' => $latitude + mt_rand(-15, 15) / 10000,
                'longitude' => $longitude + mt_rand(-15, 15) / 10000,
                'address' => $address,
                'contact_phone' => $citizen->phone,
                'photo_path' => $this->storeFile($this->photoFor($slug, $index), 'report-photos'),
                'status' => $status,
            ]);

            $this->history($report, null, ReportStatus::Open, $createdAt, $citizen, note: ReportStatusHistory::REPORT_RECEIVED_NOTE);

            $time = $createdAt->copy();
            $staff = $index % 3 === 0 ? $this->users['staff2'] : $this->users['staff'];
            [$startNote, $doneNote] = self::NOTES[$slug];
            $viaApi = $options['api'] ?? false;

            if ($status === ReportStatus::Open && $assigned) {
                $time = $this->later($time, 2, 20);
                $this->update($report, ReportStatus::Open, ReportStatus::Open, $time, "Assigned to {$department->name} for inspection.", $staff);
            }

            if ($status !== ReportStatus::Open) {
                $time = $this->later($time, 3, $daysAgo > 10 ? 60 : 30);
                $this->update($report, ReportStatus::Open, ReportStatus::InProgress, $time, $startNote, $staff);
            }

            if ($status === ReportStatus::Resolved) {
                $time = $this->later($time, 24, min(14, max(2, $daysAgo - 3)) * 24);
                $this->update($report, ReportStatus::InProgress, ReportStatus::Resolved, $time, $doneNote, $viaApi ? null : $staff, $viaApi ? $department : null);
            }

            $report->forceFill(['created_at' => $createdAt, 'updated_at' => $time])->saveQuietly();

            $popularity = $options['votes'] ?? mt_rand(0, 6);
            $voters->reject(fn (User $user) => $user->is($citizen))->shuffle(mt_rand())->take($popularity)->prepend($citizen)
                ->each(function (User $voter) use ($report, $createdAt): void {
                    $votedAt = $voter->is($report->user) ? $createdAt : $this->later($createdAt, 0, 72);
                    $report->upvotes()->create(['user_id' => $voter->id])->forceFill(['created_at' => $votedAt, 'updated_at' => $votedAt])->saveQuietly();
                });
        }
    }

    private function update(Report $report, ReportStatus $from, ReportStatus $to, Carbon $at, string $note, ?User $staff, ?Department $viaDepartment = null): void
    {
        $this->history($report, $from, $to, $at, $staff, $viaDepartment, $note);

        $report->load('department');
        $summary = $from === $to
            ? "Updated report #{$report->id} ({$to->label()})"
            : "Moved report #{$report->id} from {$from->label()} to {$to->label()}";

        $this->log(
            ActivityAction::ReportUpdated,
            $viaDepartment ? "{$viaDepartment->name} ".lcfirst($summary).' via the integration API.' : "{$summary}.",
            $report,
            array_filter(['from_status' => $from->value, 'to_status' => $to->value, 'department' => $report->department?->name, 'source' => $viaDepartment ? 'api' : null]),
            $staff,
            $at,
        );

        $report->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ReportStatusUpdated::class,
            'data' => [
                'title' => ReportStatusUpdated::titleFor($to, 'en'),
                'body' => ReportStatusUpdated::bodyFor($report->department?->name, $report->title, 'en'),
                'report_id' => $report->id,
                'status' => $to->value,
                'department' => $report->department?->name,
                'report_title' => $report->title,
            ],
            'read_at' => $at->lt(now()->subDays(3)) ? $at->copy()->addHours(mt_rand(1, 20)) : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function history(Report $report, ?ReportStatus $from, ReportStatus $to, Carbon $at, ?User $by, ?Department $byDepartment = null, ?string $note = null): void
    {
        $report->statusHistory()->create([
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $by?->id,
            'changed_by_department_id' => $byDepartment?->id,
            'note' => $note,
        ])->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(ActivityAction $action, string $description, ?object $subject, array $properties, ?User $user, Carbon $at): void
    {
        ActivityLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => $user ? '203.94.'.mt_rand(64, 95).'.'.mt_rand(2, 250) : null,
            'created_at' => $at,
        ]);
    }

    private function seedApiKey(): string
    {
        $roads = Department::where('name', 'Roads and Highways')->firstOrFail();
        [$apiKey, $plainKey] = DepartmentApiKey::issue($roads, 'Roads works-order system', true, $this->users['admin']);
        $issuedAt = now()->subDays(100);
        $apiKey->forceFill(['created_at' => $issuedAt, 'updated_at' => $issuedAt, 'last_used_at' => now()->subDays(2), 'last_used_ip' => '203.94.70.18'])->saveQuietly();

        $this->log(ActivityAction::ApiKeyCreated, "Issued API key \"{$apiKey->name}\" ({$apiKey->key_prefix}…) to {$roads->name}.", $apiKey, [], $this->users['admin'], $issuedAt);

        return $plainKey;
    }

    /**
     * A time between $min and $max hours after $from, never in the future.
     */
    private function later(Carbon $from, int $min, int $max): Carbon
    {
        return $from->copy()->addMinutes(mt_rand($min * 60, max($min, $max) * 60))->min(now()->subMinutes(mt_rand(20, 90)));
    }

    private function photoFor(string $slug, int $index): string
    {
        $photos = glob(storage_path("app/demo-photos/{$slug}/*.jpg")) ?: [];
        sort($photos);

        if ($photos === []) {
            throw new RuntimeException("No demo photos for {$slug}.");
        }

        return $slug.'/'.basename($photos[$index % count($photos)]);
    }

    private function storeFile(string $relativeSource, string $directory): string
    {
        $path = $directory.'/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, file_get_contents(storage_path('app/demo-photos/'.$relativeSource)));

        return $path;
    }

    /**
     * [author, category slug, status, days ago, location, description, options]
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int, 4: string, 5: string, 6: array<string, mixed>}>
     */
    private function reports(): array
    {
        return [
            // English
            ['dilan', 'pothole', 'resolved', 150, 'wellawatte', 'Deep pothole in the left lane of Galle Road near the Wellawatte railway station junction. Three-wheelers swerve into the next lane to avoid it and it fills with water when it rains.', ['votes' => 8]],
            ['dilan', 'broken-street-light', 'in_progress', 12, 'bambalapitiya', 'Two street lights opposite the Bambalapitiya flats have been off for over a week. The stretch is completely dark after 7pm and people walking from the bus stop feel unsafe.', ['votes' => 5]],
            ['dilan', 'garbage', 'open', 2, 'kollupitiya', 'Garbage bags have been dumped beside the canal bridge and have not been collected for four days. Dogs are tearing the bags open and the smell is bad.', ['votes' => 4]],
            ['ayesha', 'flooding', 'resolved', 120, 'havelock', 'After every heavy shower the road in front of the Havelock Town post office floods knee-deep. Vehicles stall and pedestrians cannot use the footpath.', ['votes' => 9]],
            ['ayesha', 'damaged-sign', 'resolved', 95, 'borella', 'The pedestrian crossing sign at the Borella Kanatte roundabout is bent over and facing the wrong way. Drivers are not slowing down for the crossing.', []],
            ['ayesha', 'blocked-drain', 'in_progress', 20, 'dehiwala', 'The roadside drain along Dutugemunu Street is completely blocked with silt and plastic. Water is backing up into the houses at the lower end of the lane.', ['votes' => 6]],
            ['ruwan', 'fallen-tree', 'resolved', 60, 'cinnamon', 'A large branch from a rain tree fell across the road during last night\'s storm. One lane is blocked and the branch is resting on a power line.', ['votes' => 7]],
            ['ruwan', 'road-damage', 'open', 5, 'rajagiriya', 'The road surface has cracked and sunk near the Rajagiriya flyover exit. Motorcycles are losing balance on the uneven patch, especially at night.', ['assigned' => true, 'votes' => 9]],
            ['ruwan', 'damaged-public-property', 'in_progress', 30, 'greenpath', 'Part of the boundary wall of the public playground has collapsed onto the footpath. Children use this path to walk to school.', ['votes' => 5]],
            ['dilan', 'other', 'open', 1, 'mtlavinia', 'Water has been leaking from a broken pipe on Hotel Road for two days. Clean water is running into the drain and the road edge is starting to wash away.', ['votes' => 2]],
            ['ayesha', 'pothole', 'resolved', 170, 'moratuwa', 'A row of potholes outside the Moratuwa bus stand. Buses pulling in hit them and splash muddy water onto people waiting at the stop.', ['api' => true]],
            ['ruwan', 'broken-street-light', 'resolved', 80, 'battaramulla', 'The street lamp pole near the Battaramulla junction is leaning badly after a vehicle hit it. It could fall onto the road.', []],

            // Sinhala
            ['kasun', 'pothole', 'in_progress', 9, 'nugegoda', 'නුගේගොඩ හයිලෙවල් පාරේ සුපර් මාර්කට් එක ඉදිරිපිට ලොකු වළක් තියෙනවා. වැස්ස දවස්වල වතුර පිරිලා වළ පේන්නේ නැති නිසා යතුරුපැදිකරුවන්ට අනතුරු වෙන්න පුළුවන්.', ['votes' => 7]],
            ['kasun', 'garbage', 'resolved', 110, 'maharagama', 'මහරගම පොළ අසල කසළ ගොඩවල් සති දෙකක් තිස්සේ එකතු කරලා නැහැ. දුර්ගන්ධය නිසා අවට වෙළඳසැල්වලට ලොකු ප්‍රශ්නයක්.', ['votes' => 6]],
            ['kasun', 'flooding', 'open', 3, 'kelaniya', 'කැලණිය පන්සල් පාරේ පොඩි වැස්සකට වුණත් පාර යටවෙනවා. පාසල් ළමයින්ට පාර හරහා යන්න හරිම අමාරුයි.', ['votes' => 3]],
            ['chaminda', 'broken-street-light', 'resolved', 140, 'kadawatha', 'කඩවත නගරයේ බස් නැවතුම්පොළ අසල වීදි ලාම්පු තුනක් මාසයක් තිස්සේ නිවිලා. රාත්‍රියේ පදිකයින්ට කිසිම ආරක්ෂාවක් නැහැ.', []],
            ['chaminda', 'blocked-drain', 'resolved', 75, 'kiribathgoda', 'කිරිබත්ගොඩ මහනුවර පාරේ කාණුව ප්ලාස්ටික් සහ කුණු වලින් සම්පූර්ණයෙන්ම හිරවෙලා. වැස්සට වතුර පාරට ගලනවා.', ['votes' => 4]],
            ['chaminda', 'road-damage', 'open', 4, 'kotte', 'ඇතුල් කෝට්ටේ පාරේ තාර ඉරිතැලී කැඩිලා. වාහන යනකොට ගල් කැට විසිවෙලා පදිකයින්ට වදිනවා.', []],
            ['malini', 'fallen-tree', 'in_progress', 6, 'kalubowila', 'කළුබෝවිල රෝහල් පාරේ පැරණි ගසක් පාර හරහා වැටිලා. දැන් එක පැත්තකින් විතරයි වාහන යන්නේ, ගිලන් රථවලටත් ප්‍රමාද වෙනවා.', ['votes' => 8]],
            ['malini', 'garbage', 'open', 1, 'dehiwalabeach', 'දෙහිවල වෙරළ පාරේ අයිනේ කවුරුහරි රාත්‍රියේ කසළ දාලා යනවා. කපුටෝ සහ බල්ලෝ කුණු පාර පුරා විසුරුවනවා.', ['votes' => 1]],
            ['tharindu', 'damaged-sign', 'in_progress', 15, 'wattala', 'වත්තල මීගමුව පාරේ "පාසල් කලාපය" සංඥා පුවරුව කැඩිලා බිම වැටිලා. උදේට වාහන ඉතා වේගයෙන් යනවා.', ['votes' => 4]],
            ['tharindu', 'pothole', 'resolved', 45, 'kiribathgoda', 'කිරිබත්ගොඩ හන්දියේ සිට කඩවත දෙසට යන පාරේ ගැඹුරු වළවල් කිහිපයක් තියෙනවා. ත්‍රීරෝද රථ පෙරළෙන්න ළඟයි.', ['api' => true, 'votes' => 5]],
            ['malini', 'damaged-public-property', 'open', 8, 'maharagama', 'මහරගම ප්‍රජා ශාලාව අසල පොදු බංකු දෙකක් කැඩිලා. වැඩිහිටියන්ට බස් එනකන් ඉඳගන්න තැනක් නැහැ.', ['assigned' => true]],

            // Tamil
            ['priya', 'garbage', 'in_progress', 7, 'wasilva', 'வெள்ளவத்தை டபிள்யூ.ஏ. சில்வா மாவத்தையில் குப்பைகள் ஒரு வாரமாக அகற்றப்படவில்லை. துர்நாற்றம் வீசுகிறது, கொசுக்களும் அதிகரித்துள்ளன.', ['votes' => 6]],
            ['priya', 'broken-street-light', 'open', 2, 'kotahena', 'கொட்டாஞ்சேனை வீதியில் மூன்று தெரு விளக்குகள் எரியவில்லை. இரவில் பெண்கள் தனியாக நடந்து செல்ல பயமாக உள்ளது.', ['votes' => 3]],
            ['priya', 'flooding', 'resolved', 100, 'jampettah', 'ஜம்பட்டா வீதியில் சிறிய மழைக்கே வெள்ளம் தேங்குகிறது. தாழ்வான வீடுகளுக்குள் தண்ணீர் புகுகிறது.', ['votes' => 7]],
            ['suresh', 'pothole', 'resolved', 130, 'seastreet', 'செட்டியார் தெருவில் கடைகளுக்கு முன்னால் பெரிய குழி ஒன்று உள்ளது. லொறிகள் சரக்கு இறக்கும்போது அதில் சிக்கிக்கொள்கின்றன.', []],
            ['suresh', 'blocked-drain', 'open', 3, 'kotahena', 'கொட்டாஞ்சேனை கோவில் அருகே வடிகால் அடைபட்டுள்ளது. கழிவு நீர் வீதியில் வழிந்தோடுகிறது.', ['assigned' => true, 'votes' => 5]],
            ['fathima', 'road-damage', 'in_progress', 18, 'oldmoor', 'பழைய சோனகத் தெருவில் வீதி உடைந்து பெரிய பள்ளங்கள் உருவாகியுள்ளன. பாடசாலை வாகனங்கள் மிகவும் சிரமப்படுகின்றன.', ['votes' => 4]],
            ['fathima', 'damaged-sign', 'resolved', 55, 'baseline', 'பேஸ்லைன் வீதியில் வேகக் கட்டுப்பாட்டு அறிவிப்புப் பலகை உடைந்து கீழே விழுந்துள்ளது.', []],
            ['suresh', 'fallen-tree', 'resolved', 35, 'marine', 'வெள்ளவத்தை கடற்கரை வீதியில் பெரிய மரம் ஒன்று முறிந்து விழுந்துள்ளது. அது மின் கம்பிகளுக்கு மிக அருகில் உள்ளது.', ['votes' => 6]],
            ['fathima', 'garbage', 'resolved', 25, 'colombo12', 'பள்ளிவாசல் அருகே உள்ள பொது இடத்தில் இரவில் குப்பைகள் கொட்டப்படுகின்றன. நோய் பரவும் அபாயம் உள்ளது.', ['votes' => 3]],
        ];
    }
}
