<?php

use App\Enums\AdminRole;
use App\Models\Theater;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('super-admin は全館のシアターを取得できる（T-02）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '1番シアター']);
    Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '1番シアター']);

    $admin = createAdmin(AdminRole::SuperAdmin);
    $this->actingAs($admin, 'admin');

    expect(Theater::count())->toBe(2);
});

it('cinema-admin は自館のシアターのみ取得できる（T-02）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    $ownTheater = Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '1番シアター']);
    Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '1番シアター']);

    $admin = createAdmin(AdminRole::CinemaAdmin, $gion);
    $this->actingAs($admin, 'admin');

    $theaters = Theater::all();

    expect($theaters)->toHaveCount(1);
    expect($theaters->first()->id)->toBe($ownTheater->id);
});

it('cinema-admin はIDを指定しても他館のシアターを取得できない（T-02 / 17.2.1）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '1番シアター']);
    $otherTheater = Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '1番シアター']);

    $admin = createAdmin(AdminRole::CinemaAdmin, $gion);
    $this->actingAs($admin, 'admin');

    expect(Theater::find($otherTheater->id))->toBeNull();
});

it('cinema-admin は他館のシアターを一括更新できない（T-02）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '1番シアター']);
    $otherTheater = Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '1番シアター']);

    $admin = createAdmin(AdminRole::CinemaAdmin, $gion);
    $this->actingAs($admin, 'admin');

    $updated = Theater::where('id', $otherTheater->id)->update(['name' => '書き換え']);

    expect($updated)->toBe(0);
    expect($otherTheater->fresh()->name)->toBe('1番シアター');
});

it('所属館が未設定の cinema-admin がシアターを取得しようとすると403になる', function () {
    createCinema('gion', '祇園ムビ');

    $admin = createAdmin(AdminRole::CinemaAdmin, null);
    $this->actingAs($admin, 'admin');

    Theater::all();
})->throws(HttpException::class);
