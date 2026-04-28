# Chief-Deployment-Officer

PHP単一ファイルで配布するChief-Deployment-Officerの開発用土台です。

現時点では、1エージェント限定の承認型認証と最小のファイル操作ツールまで入れています。

- 本番想定のエントリポイント: `public_html/cdo.php`
- 開発補助: `composer serve`, `composer lint`, `composer test`, `composer qa`
- MCP疎通確認用: `npm run mcp:inspect`
- 実装済みMCPメソッド: `ping`, `initialize`, `tools/list`, `tools/call`
- 公開ツール: `server_status`, `request_auth`
- 保護ツール: `list_dir`, `read_file`, `write_file`, `create_dir`, `delete_file`, `delete_dir`, `rename_path`

## 前提

- PHP 7.4+ を本番対象に想定
- ローカル確認は PHP 8.5 系で実施
- Node.js 22.7.5+ があると MCP Inspector を使えます

## セットアップ

```powershell
composer install
npm install
```

## よく使うコマンド

```powershell
composer serve
composer qa
npm run mcp:inspect
```

`composer serve` は長時間起動のままで使う想定なので、Composer の process timeout は無効化しています。

`composer serve` の後は、ブラウザ確認なら `http://127.0.0.1:8787/` を開けます。

MCP endpoint は次のどちらでも使えます。

- 推奨: `http://127.0.0.1:8787/mcp`
- 実ファイル直指定: `http://127.0.0.1:8787/cdo.php`

`/sse` は dev router で `cdo.php` に流れますが、SSE transport 自体は未実装です。MCP Inspector では Streamable HTTP を選び、URL は `/mcp` か `/cdo.php` を指定してください。

現時点の認証は OAuth/OIDC ではありません。`/.well-known/oauth-protected-resource` などの discovery endpoint も未実装です。MCP Inspector の OAuth フローは使わず、`request_auth` で発行された token を手動でヘッダー設定してください。

## ファイル名変更と認証上の注意

`public_html/cdo.php` は任意の `.php` ファイル名に変更しても動く前提です。公開環境では `cdo` 部分を推測されにくい名前へ変更し、MCP endpoint URL も変更後のファイル名で指定してください。

この認証フローは「ユーザーが承認した1エージェントだけに Bearer token を渡す」ための最小防御です。ファイル名変更は機械的な探索を減らす運用上の補助であり、単独の防御策として扱わないでください。

## 本番配置前チェック

- HTTPS環境で使ってください。HTTPでは bearer token と承認URLが盗聴されるリスクがあります
- `cdo.php` は推測されにくいPHPファイル名に変更し、MCP endpoint URL も変更後のファイル名で指定してください
- サーバー側でIP制限、Basic認証、管理画面配下配置などの追加アクセス制限を検討してください
- `write_file`, `delete_file`, `delete_dir`, `rename_path` を使う前に、対象ディレクトリのバックアップを取ってください
- 不要になった認証は `public_html/.cdo_auth.json` を削除してリセットしてください
- `public_html/.cdo_auth.json` と `public_html/.cdo_debug.log` は公開配布物・共有物に含めないでください

## 複数エージェントで使う場合

Chief-Deployment-Officer は `1ファイル=1エージェント認証` の運用を前提にします。`cdo.php` は配置ディレクトリごとに `.cdo_auth.json` を持つため、複数エージェントが必要な場合は `agent-a/cdo.php`, `agent-b/cdo.php` のようにサブディレクトリへコピーして使ってください。

各コピーは別URL・別認証状態・別承認フローとして扱われます。1つの `cdo.php` の中で複数エージェントを管理する機能は追加しません。

## 認証フロー

1. 未認証の状態で `request_auth` を呼びます。
2. 応答の `approvalUrl` をブラウザで開き、ユーザーが `はい` を押します。
3. 同じ応答に含まれる `bearerToken` を `X-CDO-Bearer-Token: ...` で送ると、保護ツールが使えます。

MCP Inspector では `Authentication` パネルの custom headers に `X-CDO-Bearer-Token: <bearerToken>` を追加してください。`Authorization: Bearer ...` も受け付けますが、Inspector では専用ヘッダーのほうが切り分けしやすいです。

認証状態は `public_html/.cdo_auth.json` に保存されます。削除すると新しいエージェントを承認できます。
認証デバッグログは `public_html/.cdo_debug.log` に JSON Lines で追記されます。

## デバッグ

`server_status` は接続確認だけでなく、現在の認証判定も返します。特に次の項目で切り分けできます。

- `authorizationHeaderPresent`: Authorization ヘッダーがサーバーに届いたか
- `inspectorBearerHeaderPresent`: `X-CDO-Bearer-Token` ヘッダーがサーバーに届いたか
- `authorized`: 現在のリクエストが承認済みとして通ったか
- `authReason`: `missing_state`, `missing_token`, `invalid_token`, `authorized` などの理由
- `bearerHeaderSource`: 実際に採用したヘッダー名
- `tokenHashPrefix` と `storedTokenHashPrefix`: 送信 token と保存済み token hash の先頭一致確認

Inspector で保護ツールが見えない場合は、まず `server_status` を呼んでこの 4 項目を見てください。そのうえで `public_html/.cdo_debug.log` を開くと、`tools/list` と `auth_context` の判定履歴が確認できます。

開発やテストで状態ファイルを分離したい場合は、`CDO_AUTH_STATE_PATH` と `CDO_DEBUG_LOG_PATH` で保存先を上書きできます。

## 危険操作の運用ルール

- `write_file`, `delete_file`, `delete_dir`, `rename_path` は承認済みエージェントだけに使わせてください
- 削除・リネーム前には `list_dir` / `read_file` で対象パスと内容を確認してください
- `confirm: true` は、ユーザーが対象と操作内容を確認した後だけ付けてください
- `delete_dir` は空ディレクトリのみ対応します。再帰削除は実装していません
- `rename_path` は宛先が既に存在する場合は拒否します。リネーム時の上書き・置換は実装していません

## list_dir

- ルートは `cdo.php` の配置ディレクトリです
- `path` の既定値は `.` です
- 返すのは直下エントリだけで、再帰しません
- `.cdo_*` は内部制御ファイルとして一覧から除外します
- 一般の dotfile は除外しません

## read_file

- ルートは `cdo.php` の配置ディレクトリです
- `path` は必須で、絶対パスと `..` は拒否します
- `.cdo_*` で始まる内部制御ファイルは読み取り対象外です
- `maxBytes` で読み取り上限を指定できます。上限は `1048576` bytes です
- UTF-8として読める内容はそのまま返し、バイナリは base64 で返します

## write_file

- ルートは `cdo.php` の配置ディレクトリです
- `path`, `content`, `encoding` は必須で、`encoding` は `utf-8` または `base64` です
- 既存ファイルは `overwrite: true` がない限り拒否します
- 親ディレクトリは暗黙作成しません。必要な場合は先に `create_dir` を呼びます
- 絶対パス、`..`, `.cdo_*` 内部制御ファイル、現在のエントリポイントファイル自身への書き込みは拒否します

## create_dir

- ルートは `cdo.php` の配置ディレクトリです
- `path` は必須で、絶対パスと `..` は拒否します
- `recursive: true` の場合だけ親ディレクトリも作成します
- 既存パスがディレクトリなら成功扱い、ファイルなら拒否します
- `.cdo_*` 内部制御ファイル名と現在のエントリポイントファイル自身は作成対象外です

## delete_file

- ルートは `cdo.php` の配置ディレクトリです
- `path` と `confirm: true` は必須です
- 絶対パス、`..`, `.cdo_*` 内部制御ファイル、現在のエントリポイントファイル自身は拒否します
- ディレクトリは削除できません。ディレクトリ削除には `delete_dir` を使います

## delete_dir

- ルートは `cdo.php` の配置ディレクトリです
- `path` と `confirm: true` は必須です
- 空ディレクトリのみ削除できます。非空ディレクトリとファイルは拒否します
- 再帰削除は実装していません
- 絶対パス、`..`, `.cdo_*` 内部制御ファイル、現在のエントリポイントファイル自身は拒否します

## rename_path

- ルートは `cdo.php` の配置ディレクトリです
- `from`, `to`, `confirm: true` は必須です
- ファイルとディレクトリの名前変更・移動に対応します
- 宛先が既に存在する場合は常に拒否し、上書きや置換はしません
- 宛先の親ディレクトリは暗黙作成しません
- 移動元・移動先とも、絶対パス、`..`, `.cdo_*` 内部制御ファイル、現在のエントリポイントファイル自身は拒否します

## ディレクトリ構成

- `public_html/`: 本番配布を意識した単一ファイル置き場
- `dev/qa/`: lint などの補助スクリプト
- `dev/tests/`: 依存なしで動くスモークテスト

## 現在の実装範囲

- 単一ファイル運用を崩さないため、本体のランタイム依存はまだ追加していません
- MCP Inspector は開発用の外部ツールとしてのみ追加しています
- `cdo.php` はファイル名固定に依存しない書き方で育てる前提です
- 削除はファイルと空ディレクトリのみ対応し、再帰削除は未実装です
- リネーム時の上書き・置換は未実装です
