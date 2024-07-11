<?php

namespace Database\Seeders;

use App\Models\PostType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{

    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // $this->call(EligibleRoles::class);
        /*
        ##### DONE ON SERVER#####
        // $this->call(PermissionsSeeder::class);
        // $this->call(TypeSectionSeeder::class); //contains all the types and sections
        // $this->call(TimelineSeeder::class);
        // $this->call(ReactionSeeder::class);
        // $this->call(BookStatisticsSeeder::class);
        // $this->call(AuditTypeSeeder::class);
        //$this->call(BookSeeder::class);
        // $this->call(booksMediaSeeder::class);
        // $this->call(ModificationReasonSeeder::class);
        //$this->call(BookV2Seeder::class);

        //$this->call(BooksRoleSeeder::class);
        //$this->call(NewSectionsLevelsSeeder::class);

        ######LOCAL#######
        // $this->call(PermissionsSeeder::class);
        // $this->call(TypeSectionSeeder::class); //contains all the types and sections
        // $this->call(TimelineSeeder::class);
        // $this->call(ReactionSeeder::class);
        // $this->call(BookStatisticsSeeder::class);
        // $this->call(AuditTypeSeeder::class);
        // $this->call(BookSeeder::class);
        // $this->call(booksMediaSeeder::class);
        // $this->call(GroupSeeder::class);
        // $this->call(UserSeeder::class);
        // $this->call(WeekSeeder::class); //comment this out if you want to run the thesis seeder
        // $this->call(PostSeeder::class);
        // $this->call(BooksRoleSeeder::class);
        // $this->call(NewSectionsLevelsSeeder::class);

        // $this->call(InfographicSeeder::class);
        // $this->call(InfographicSeriesSeeder::class);
        // $this->call(ArticleSeeder::class);
        // $this->call(CommentSeeder::class);
        // $this->call(ThesisSeeder::class);
        // $this->call(FriendSeeder::class);
        // $this->call(ModificationReasonSeeder::class);
        // $this->call(ModifiedThesesSeeder::class);
        */

        // $this->call(RamadanRoles::class);
        // Artisan::call('db:seed', ['--class' => 'Database\Seeders\Ramadan\RamadanDays']);
        // Artisan::call('db:seed', ['--class' => 'Database\Seeders\Ramadan\HadithSeeder']);
        // Artisan::call('db:seed', ['--class' => 'Database\Seeders\Ramadan\QuestionsSeeder']);
    }
}
