<?php
/**
 * NDASA Donation Platform
 *
 * @package    NDASA\Donation
 * @author     John O'Grady <john@status26.com>
 * @copyright  2026 NDASA Foundation
 * @license    Proprietary - NDASA Foundation
 */
declare(strict_types=1);

namespace NDASA\Support;

/**
 * Donor-header rotating slogans. Each pair is an uppercase eyebrow lead
 * followed by an italic-body phrase. Kept here (not in a .json file) so
 * copy changes are tracked in git and don't require a cache bust.
 *
 * Tone guide: gravitas, specificity, mission language from the parent
 * site (prevention, recovery, scholarships, first responders, families,
 * drug-free communities). Avoid generic charity filler.
 */
final class Slogans
{
    /**
     * @return list<array{lead:string, body:string}>
     */
    public static function donor(): array
    {
        return [
            ['lead' => 'Every dollar',     'body' => 'rebuilds a drug-free future.'],
            ['lead' => 'One gift',         'body' => 'seeds a lifetime of recovery.'],
            ['lead' => 'Your generosity',  'body' => 'reaches the family next door.'],
            ['lead' => 'A single choice',  'body' => 'changes the path ahead.'],
            ['lead' => 'Prevention',       'body' => 'is the work of everyone.'],
            ['lead' => 'Hope',             'body' => 'begins with a name on a card.'],
            ['lead' => 'Real support',     'body' => 'starts before the crisis.'],
            ['lead' => 'Quiet giving',     'body' => 'lifts whole communities.'],
            ['lead' => 'Help reach',       'body' => 'the student who needs you.'],
            ['lead' => 'First responders', 'body' => 'answer the call. So can you.'],
            ['lead' => 'Teachers, parents,', 'body' => 'counselors — all rely on you.'],
            ['lead' => 'Scholarships',     'body' => 'begin with donors like you.'],
            ['lead' => 'A safer block',    'body' => 'starts with a single decision.'],
            ['lead' => 'Every family',     'body' => 'deserves the chance to heal.'],
            ['lead' => 'Education',        'body' => 'is the surest prevention.'],
            ['lead' => 'Advocacy',         'body' => 'turns awareness into action.'],
            ['lead' => 'Recovery',         'body' => 'is never walked alone.'],
            ['lead' => 'Give today.',      'body' => 'Change tomorrow.'],
            ['lead' => 'Small gifts',      'body' => 'carry enormous weight.'],
            ['lead' => 'Every story',      'body' => 'began with someone caring.'],
            ['lead' => 'Communities',      'body' => 'heal when neighbors show up.'],
            ['lead' => 'Your name',        'body' => 'joins a quiet, lasting chorus.'],
            ['lead' => 'We answer',        'body' => 'only because you do.'],
            ['lead' => 'The work',         'body' => 'is possible because of you.'],
            ['lead' => 'Steady support',   'body' => 'builds unshakable progress.'],
            ['lead' => 'A gift of $25',    'body' => 'is the start of a movement.'],
            ['lead' => 'Dignity',          'body' => 'restored, one student at a time.'],
            ['lead' => 'Stand for',        'body' => 'the kids who can’t yet.'],
            ['lead' => 'Be the reason',    'body' => 'a family holds together.'],
            ['lead' => 'Your choice',      'body' => 'keeps doors open to help.'],
            ['lead' => 'The quiet good',   'body' => 'lives in every gift made.'],
            ['lead' => 'Reach further',    'body' => 'than you thought possible.'],
            ['lead' => 'No effort',        'body' => 'to heal is ever wasted.'],
            ['lead' => 'Legacy',           'body' => 'is written in daily acts.'],
            ['lead' => 'A healthier town', 'body' => 'is a gift we give together.'],
            ['lead' => 'Trust',            'body' => 'is built one donation at a time.'],
            ['lead' => 'Your gift',        'body' => 'meets the moment someone needs.'],
            ['lead' => 'Give with',        'body' => 'the courage the moment asks.'],
            ['lead' => 'Give because',     'body' => 'the next child is watching.'],
            ['lead' => 'Give because',     'body' => 'someone once gave for you.'],
            ['lead' => 'Outreach',         'body' => 'begins where donors stand.'],
            ['lead' => 'Compassion',       'body' => 'becomes real on this page.'],
            ['lead' => 'Invest in',        'body' => 'the people who do the work.'],
            ['lead' => 'Lift up',          'body' => 'the voices still finding their way.'],
            ['lead' => 'Donate to',        'body' => 'widen the circle of care.'],
            ['lead' => 'Support the',      'body' => 'ones who support everyone else.'],
            ['lead' => 'Mentors,',         'body' => 'counselors, neighbors — all of us.'],
            ['lead' => 'Hold the line',    'body' => 'for a healthier generation.'],
            ['lead' => 'Make the call',    'body' => 'others haven’t been able to.'],
            ['lead' => 'Give today',       'body' => 'what the world needs tomorrow.'],
        ];
    }
}
