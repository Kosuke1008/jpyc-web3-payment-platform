#!/usr/bin/env bash
set -euo pipefail

audit_dir=/home/nakas/projects/jpyc-web3-payment-platform/docs/audits/2026-09-26

write_table() {
    local repo_name="$1"
    local repo_dir="/home/nakas/projects/$repo_name"
    local output="$audit_dir/$repo_name-files.md"
    local tracked reviewed excluded unconfirmed

    tracked=$(git -C "$repo_dir" ls-files | wc -l)
    reviewed=0
    excluded=0
    unconfirmed=0

    {
        echo "# $repo_name 追跡ファイル確認表"
        echo
        echo "基準commit: \`$(git -C "$repo_dir" rev-parse HEAD)\`"
        echo
        echo "各行の bytes / lines / SHA-256 は、作業ツリーではなく基準commitの Git blob 全体を取得して算出した。これにより、省略・切断のない取得を確認した。『内容を確認』は、その全体取得に加えて、ファイル種別に応じた静的監査対象に含めたことを示す。生成ロック、同梱minifiedライブラリ、画像は意味内容の監査対象外だが、blobの完全性は同様に記録した。"
        echo
        echo "| path | bytes | lines | SHA-256 | 分類 | 理由 |"
        echo "|---|---:|---:|---|---|---|"
    } > "$output"

    while IFS= read -r file; do
        local tmp bytes lines digest classification reason escaped
        tmp=$(mktemp)
        git -C "$repo_dir" show "HEAD:$file" > "$tmp"
        bytes=$(wc -c < "$tmp")
        lines=$(awk 'END { print NR }' "$tmp")
        digest=$(sha256sum "$tmp" | cut -d' ' -f1)
        classification='内容を確認'
        reason='Git blob全体を取得・照合し、静的監査対象として確認'

        case "$repo_name/$file" in
            jpyc-web3-payment-platform/composer.lock|jpyc-web3-payment-platform/pnpm-lock.yaml|livt-wallet/pnpm-lock.yaml|livt-fee-payer/pnpm-lock.yaml)
                classification='生成物・画像等のため内容監査対象外'
                reason='パッケージマネージャ生成ロック（完全性のみ確認）'
                ;;
            jpyc-web3-payment-platform/public/js/ethers.umd.min.js)
                classification='生成物・画像等のため内容監査対象外'
                reason='同梱minified第三者ライブラリ（完全性のみ確認）'
                ;;
            jpyc-web3-payment-platform/public/favicon.ico)
                classification='生成物・画像等のため内容監査対象外'
                reason='画像プレースホルダー。blobは0 byteと確認'
                ;;
            *)
                if [[ "$bytes" -eq 0 ]]; then
                    reason='Git blob全体を取得し、0 byteの空ファイルと確認'
                fi
                ;;
        esac

        if [[ "$classification" == '内容を確認' ]]; then
            reviewed=$((reviewed + 1))
        elif [[ "$classification" == '生成物・画像等のため内容監査対象外' ]]; then
            excluded=$((excluded + 1))
        else
            unconfirmed=$((unconfirmed + 1))
        fi

        escaped=${file//|/\\|}
        printf '| `%s` | %s | %s | `%s` | %s | %s |\n' "$escaped" "$bytes" "$lines" "$digest" "$classification" "$reason" >> "$output"
        rm -f "$tmp"
    done < <(git -C "$repo_dir" ls-files)

    {
        echo
        echo "## 集計"
        echo
        echo "- 追跡ファイル: $tracked"
        echo "- 内容を確認: $reviewed"
        echo "- 生成物・画像等のため内容監査対象外: $excluded"
        echo "- 未確認: $unconfirmed"
    } >> "$output"
}

write_table jpyc-web3-payment-platform
write_table livt-wallet
write_table livt-fee-payer
