# Chief-Deployment-Officer

[English README](README.md)

PHP単一ファイルで配布するChief-Deployment-Officerの開発用土台です。

このREADMEは、`cdo.php` を開発・配布する人と、実装内容を把握したいAIエージェント向けです。承認操作をするユーザー向けの案内は、配置済み `cdo.php` のブラウザ画面と承認画面に集約します。

現時点では、1エージェント限定の承認型認証と最小のファイル操作ツールまで入れています。

- 本番想定のエントリポイント: `public_html/cdo.php`
- 開発補助: `composer serve`, `composer lint`, `composer test`, `composer qa`
- MCP疎通確認用: `npm run mcp:inspect`
- 実装済みMCPメソッド: `ping`, `initialize`, `tools/list`, `tools/call`
- 公開ツール: `server_status`, `request_auth`
- 保護ツール: `list_dir`, `read_file`, `write_file`, `create_dir`, `delete_file`, `delete_dir`, `rename_path`, `get_env_path`, `request_env_upload`, `get_runtime_info`

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

ブラウザまたはAIエージェントが `cdo.php` にアクセスすると、MCP endpoint、認証状態、承認済みエージェント情報、危険操作の注意をまとめた案内ページを表示します。この画面はユーザー向けセクションを先に、AIエージェント向けセクションを後に表示します。

AIエージェントにリモートの `cdo.php` URL を渡す場合は、そのURL自体を MCP endpoint としてそのまま使わせてください。AIエージェント側ではローカルリポジトリや別パスを推測せず、まず配置済みページのAI向けセクションを読むか、`server_status` の `agentGuide` を確認します。そのうえで `tools/list`、次に `server_status`、必要なら `request_auth` の順に呼びます。

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
- 不要になった認証は、配置先ディレクトリの関連認証ファイルを削除してリセットしてください。例: `cdo.php` は `.cdo_auth.json`, `agent-a.php` は `.agent-a_auth.json`
- `.*_auth.json`, `.*_env.json`, `.*_debug.log` は公開配布物・共有物に含めないでください
- 未認証のCDOファイルを公開サーバーに置いたまま放置しないでください。使わないコピーは削除してください

## 複数エージェントで使う場合

Chief-Deployment-Officer は `1ファイル=1エージェント認証` の運用を前提にします。関連ファイルはエントリポイントのファイル名に連動するため、同じディレクトリに `agent-a.php`, `agent-b.php` のような別名コピーを置いても別認証状態として扱われます。

例: `cdo.php` は `.cdo_auth.json`, `.cdo_env.json`, `.cdo_debug.log` を使い、`agent-a.php` は `.agent-a_auth.json`, `.agent-a_env.json`, `.agent-a_debug.log` を使います。env保存先のhashもファイル名を含めて分かれます。サブディレクトリに置いたコピーも、そのコピー自身のパスとMCP endpointを表示します。1つのCDOファイルの中で複数エージェントを管理する機能は追加しません。

## 認証フロー

1. 未認証の状態で `request_auth` を呼びます。
2. 応答の `approvalUrl` をブラウザで開き、ユーザーが `承認する` を押します。
3. 同じ応答に含まれる `bearerToken` を `X-CDO-Bearer-Token: ...` で送ると、保護ツールが使えます。

`request_auth` には任意で `agentName` と `contextHint` を渡せます。`contextHint` は、あとでユーザーが承認した対話スレッドを探すための短い手がかりです。例: `Codex desktop / Chief-Deployment-Officer release thread / 2026-04-28`。秘密情報やtokenは入れないでください。

承認画面と承認完了画面はユーザー向けです。AIエージェントは `request_auth` の応答に含まれる `approvalUrl` をユーザーに提示し、「開いて承認したら知らせてください」と伝えます。同じ応答に含まれる `bearerToken` はAIエージェント側で保持し、ユーザーに貼り返してもらわない運用にしてください。承認後は `X-CDO-Bearer-Token: <bearerToken>` を付けて `server_status` を再確認します。

MCP Inspector では `Authentication` パネルの custom headers に `X-CDO-Bearer-Token: <bearerToken>` を追加してください。`Authorization: Bearer ...` も受け付けますが、Inspector では専用ヘッダーのほうが切り分けしやすいです。

認証状態は既定ではエントリポイントと同じディレクトリの関連認証ファイルに保存されます。`cdo.php` なら `.cdo_auth.json`, `agent-a.php` なら `.agent-a_auth.json` です。削除すると新しいエージェントを承認できます。
認証デバッグログもファイル名に連動し、`cdo.php` なら `.cdo_debug.log`, `agent-a.php` なら `.agent-a_debug.log` に JSON Lines で追記されます。

## デバッグ

`server_status` は接続確認だけでなく、現在の認証判定も返します。特に次の項目で切り分けできます。

- `endpoint`: 現在のリクエストから組み立てた MCP endpoint URL
- `agentGuide`: AIエージェント向けの接続手順、認証手順、env配置方針、危険操作ルール、timeout時の確認手順
- `authorizationHeaderPresent`: Authorization ヘッダーがサーバーに届いたか
- `inspectorBearerHeaderPresent`: `X-CDO-Bearer-Token` ヘッダーがサーバーに届いたか
- `authorized`: 現在のリクエストが承認済みとして通ったか
- `authReason`: `missing_state`, `missing_token`, `invalid_token`, `authorized` などの理由
- `bearerHeaderSource`: 実際に採用したヘッダー名
- `agentName`, `contextHint`, `approvedAt`, `lastUsedAt`: どのAIエージェントにいつ承認したかの確認情報

Inspector で保護ツールが見えない場合は、まず `server_status` を呼んで上記項目を見てください。そのうえで配置先ディレクトリの関連debug logを開くと、`tools/list` と `auth_context` の判定履歴が確認できます。

開発やテストで状態ファイルを分離したい場合は、`CDO_AUTH_STATE_PATH`, `CDO_ENV_STATE_PATH`, `CDO_DEBUG_LOG_PATH` で保存先を上書きできます。

## get_runtime_info

`get_runtime_info` は承認済みエージェント向けの安全化したruntime診断です。生の `phpinfo()` HTML、`$_ENV`, `$_SERVER` 全量、headers、cookies、環境変数値は返しません。

返す情報は、PHPのバージョン/SAPI/OS、読み込まれた `php.ini` と追加iniファイル、`.user.ini` と `.htaccess` でPHPディレクティブを変更できる見込み、`ini_get_all(null, true)` を整形した全ディレクティブ、読み込み済み拡張一覧、主要拡張の有無です。

ディレクティブごとに `globalValue`, `effectiveValue`, `overridden`, `accessRaw`, `accessLabels`, `settableVia` を返します。`.user.ini` と `.htaccess` の可否はPHP SAPIと関連ini値からの見込みであり、ホスティング会社の `AllowOverride` などサーバー側設定までは断定しません。

## 危険操作の運用ルール

- `write_file`, `delete_file`, `delete_dir`, `rename_path` は承認済みエージェントだけに使わせてください
- 削除・リネーム前には `list_dir` / `read_file` で対象パスと内容を確認してください
- `confirm: true` は、ユーザーが対象と操作内容を確認した後だけ付けてください
- 書込・削除・リネームのtool callがtimeoutした場合、AIエージェントは同じ操作を即時再実行せず、まず `server_status`、次に `list_dir` / `read_file` で反映状態を確認してください
- 反映済みなら成功扱いにし、未反映ならユーザーに確認してから再実行してください
- `delete_dir` は空ディレクトリのみ対応します。再帰削除は実装していません
- `rename_path` は宛先が既に存在する場合は拒否します。リネーム時の上書き・置換は実装していません
- `request_env_upload` は人間向けブラウザURLだけを発行します。AIエージェントにenv本文を貼らせたり、MCP経由でアップロードさせたりしないでください

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
- `overwrite: true` で既存ファイルを上書きする場合、既存ファイルのパーミッションを維持します
- 新規ファイル作成時のパーミッションは固定値ではなく、サーバーの `umask` に従う通常作成相当の `0666 & ~umask()` です
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

## production.env / Secrets配置MVP

CDOはOS環境変数を恒久設定しません。共有サーバーでは、PHPリクエスト中の `putenv()` で親プロセスや将来のリクエストの環境変数を変更できないためです。

代わりに、公開領域の外に本番用envファイルを1つだけ配置します。`DOCUMENT_ROOT` の祖先に `public_html` がある場合は、その `public_html` の1つ上に `.cdo-secrets/{cdo_app_root_hash}/production.env` を置きます。`public_html` が見つからない場合は、`DOCUMENT_ROOT` の1つ上を使います。`cdo_app_root_hash` は配置ディレクトリとエントリポイントファイル名から作るため、同じディレクトリ内の別名CDOでも保存先が分かれます。候補一覧は返さず、CDOが単一の `envPath` を決めます。

- `get_env_path`: `envPath`, `available`, `uploaded`, `uploadedAt`, `outsideDocumentRoot`, `readableByPhp`, `writable`, `reason` だけを返します
- `request_env_upload`: 10分有効・1回限りのブラウザ用アップロードURLを発行します。既存の未使用URLは破棄されます

これらのツールはenv本文、キー名、値、ダウンロードURLを返しません。AIエージェントは `envPath` だけを受け取り、対象アプリのコードを「そのenvファイルを読む」形に修正します。env本文の投入は、ユーザーがブラウザのワンタイムアップロード画面で行います。

通常フローは `get_env_path` で配置パスとアップロード状態を確認し、未アップロードなら `request_env_upload` でユーザー向けURLを発行し、アップロード後にもう一度 `get_env_path` で `uploaded` と `uploadedAt` を確認します。

アップロード画面は任意のファイル名を受け付けますが、保存先ファイル名は常に `production.env` です。最大サイズは `256KB`、NULL byteを含むファイルは拒否します。保存は一時ファイル経由で置き換え、可能ならファイル権限を `0600` にします。バックアップは作りません。

ドキュメントルート配下、または `public_html` 配下にしか安全な保存先を確保できない場合、CDOはenv配置を拒否します。独自ドメインのドキュメントルートがデフォルト公開ディレクトリ配下にある共有サーバーでも、デフォルトドメインから見える可能性がある `public_html` 配下には配置しません。`.htaccess`, `.user.ini`, `php.ini`, FPM pool設定は動的生成しません。その場合は、ホスティング会社が提供する環境変数設定、Secrets設定、または管理画面の設定機能を使ってください。

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
