<?php

declare(strict_types=1);

arch()->preset()->php();
arch()->preset()->security();

arch('the package ships no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'exit'])
    ->not->toBeUsed();

arch('every source file declares strict types')
    ->expect('Difflock')
    ->toUseStrictTypes();

arch('source classes are final unless deliberately extended')
    ->expect('Difflock')
    ->classes()
    ->toBeFinal();

arch('contracts are interfaces')
    ->expect('Difflock\Contracts')
    ->toBeInterfaces();

/*
 * The separation the README promises, enforced rather than asserted.
 *
 * A rule that could reach Artisan would eventually print something; a rule that
 * could reach the database would eventually run a query per migration. Neither is a
 * mistake anybody makes on purpose, and neither is caught by a test of behaviour.
 */
arch('rules know nothing about the console or the database')
    ->expect('Difflock\Migration\Rules')
    ->not->toUse([
        'Illuminate\Console',
        Illuminate\Support\Facades\DB::class,
        Illuminate\Database\Connection::class,
        'Difflock\Console',
    ]);

arch('the diff engine knows nothing about rendering')
    ->expect('Difflock\Diff')
    ->not->toUse(['Difflock\Console', 'Illuminate\Console', 'Symfony\Component\Console']);

arch('the risk model depends on nothing that could print it')
    ->expect('Difflock\Risk')
    ->not->toUse(['Difflock\Console', 'Illuminate\Console', 'Symfony\Component\Console']);

arch('the parser executes nothing')
    ->expect('Difflock\Migration\Parser')
    ->not->toUse(['eval', 'include', 'require', 'Illuminate\Database', 'Illuminate\Support\Facades']);

arch('protection consumes analysis rather than repeating it')
    ->expect('Difflock\Protection')
    ->not->toUse(['Difflock\Migration\Rules', 'Difflock\Migration\Parser', 'Illuminate\Console']);
