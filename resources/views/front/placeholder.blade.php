{{--
    工程2（館切替とルーティング）の検証用プレースホルダ。
    館別ページの実装（工程4以降）で画面ごとのビューへ差し替える。
--}}
<x-front.layout :title="$screenId.' / '.$cinema->name">
    <div class="px-4 py-8">
        <p data-testid="screen-id">{{ $screenId }}</p>
        <p data-testid="cinema-slug">{{ $cinema->slug }}</p>
        <p data-testid="cinema-name">{{ $cinema->name }}</p>
    </div>
</x-front.layout>
