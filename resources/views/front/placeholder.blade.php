{{--
    工程2（館切替とルーティング）の検証用プレースホルダ。
    館別ページの実装（工程4以降）で画面ごとのビューへ差し替える。
--}}
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $screenId }} / {{ $cinema->name }}</title>
</head>
<body>
    <p data-testid="screen-id">{{ $screenId }}</p>
    <p data-testid="cinema-slug">{{ $cinema->slug }}</p>
    <p data-testid="cinema-name">{{ $cinema->name }}</p>
</body>
</html>
