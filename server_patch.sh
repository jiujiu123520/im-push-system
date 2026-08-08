#!/bin/bash
# ============================================================
# 一键补丁 — 修复 install.sh / manage.sh / uninstall.sh
# 使用: cd ~/im-push-system && bash server_patch.sh
# ============================================================
set -e
cd "$(dirname "$0")"

echo "=== 1/3 修复 deploy/install.sh ==="
# 把 install_mysql_safe() 里嵌套的 has_any_swap/get_nproc 提到顶层
# 当前服务器结构: 791 has_any_swap(), 801 get_nproc(), 1194 MAKE_JOBS
# 步骤: 提取函数 → 删除嵌套定义 → 插入顶层

cp deploy/install.sh deploy/install.sh.bak2

# 1a. 提取嵌套的两个函数（has_any_swap 到 get_nproc 结束），去缩进
sed -n '791,814p' deploy/install.sh | sed 's/^    //' > /tmp/_funcs.txt

# 1b. 删除嵌套定义（791-814行）
sed -i '791,814d' deploy/install.sh

# 1c. 找到 repair_apt_cache 函数的行号，插到它前面
RAC_LINE=$(grep -n "^repair_apt_cache()" deploy/install.sh | head -1 | cut -d: -f1)
awk -v t="$RAC_LINE" 'NR==t{while((getline l<"/tmp/_funcs.txt")>0)print l} {print}' deploy/install.sh > /tmp/_install.sh
mv /tmp/_install.sh deploy/install.sh

# 验证
echo "  函数位置:"
grep -n "^has_any_swap\|^get_nproc\|^install_mysql_safe\|^repair_apt_cache\|MAKE_JOBS=\|if ! has_any_swap" deploy/install.sh | sed 's/^/    /'

bash -n deploy/install.sh && echo "  ✓ 语法OK" || { echo "  ✗ 语法错误!"; exit 1; }

echo ""
echo "=== 2/3 修复 manage.sh ==="
cp manage.sh manage.sh.bak2

# 在第 896 行后插入 trim+lower 行，并修改判断条件
# 原: _safe_read ... reply
#     if [[ "$reply" == "yes" ]]; then
# 新: _safe_read ... reply
#     reply=$(echo "${reply// /}" | tr '[:upper:]' '[:lower:]')
#     if [[ "$reply" == "yes" || "$reply" == "y" ]]; then

# 找到那两行的行号
R1=$(grep -n "_safe_read \"确认完全卸载" manage.sh | head -1 | cut -d: -f1)
R2=$((R1 + 1))

# 在 R1 后插入 trim 行
sed -i "${R1}a\\    reply=\$(echo \"\${reply// /}\" | tr '[:upper:]' '[:lower:]')" manage.sh

# 修改 R2（已变成 R1+2）的判断条件
sed -i "s/if \[\[ \"\$reply\" == \"yes\" \]\]; then/if [[ \"\$reply\" == \"yes\" || \"\$reply\" == \"y\" ]]; then/" manage.sh

echo "  关键片段:"
sed -n '893,902p' manage.sh | sed 's/^/    /'

bash -n manage.sh && echo "  ✓ 语法OK" || { echo "  ✗ 语法错误!"; exit 1; }

echo ""
echo "=== 3/3 修复 deploy/uninstall.sh ==="
cp deploy/uninstall.sh deploy/uninstall.sh.bak2

# 在两处 read -p 后面各插入一行 trim
# 第一处: "确认执行卸载? 输入 'yes' 继续"
sed -i "/read -p \"确认执行卸载\? 输入 'yes' 继续/a\\    reply=\$(echo \"\${reply// /}\" | tr '[:upper:]' '[:lower:]')" deploy/uninstall.sh

# 第二处: "是否同时卸载数据库 MySQL/MariaDB"
sed -i "/read -p \"是否同时卸载数据库 MySQL\/MariaDB/a\\            reply=\$(echo \"\${reply// /}\" | tr '[:upper:]' '[:lower:]')" deploy/uninstall.sh

echo "  关键片段:"
grep -n "reply=" deploy/uninstall.sh | sed 's/^/    /'

bash -n deploy/uninstall.sh && echo "  ✓ 语法OK" || { echo "  ✗ 语法错误!"; exit 1; }

echo ""
echo "=== 全部修复完成 ✓ ==="
echo "备份文件: *.bak2"
echo "现在运行: sudo ./manage.sh"
