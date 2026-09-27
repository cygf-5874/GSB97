# evolveproj · 事件溯源投影（升版 + 快照）

PHP 8，只用标准库（php-cli，无 composer，无第三方依赖；本机 php-cli 未启用 mbstring，别用）。

```bash
php tests/run.php        # 既有用例（单版本重放 + 快照往返）
bash scripts/check.sh    # 固定验收程序（11 个场景，别改）
```

## 目录

```
autoload.php               Evolveproj\Foo → src/Foo.php
src/Projection.php         投影：投递、状态、策略、快照出入口
src/Event.php              不可变事件
src/ProjectionError.php    统一异常（带 kind）
src/Upcast.php             升版器注册表          ← 本次要补的一层
src/Snapshot.php           快照 blob 编解码器     ← 本次要补的一层
tests/run.php             既有用例，14 个，当前全绿
check/check.php           固定验收程序，11 个场景
scripts/check.sh          check.php 的薄封装
```

当前模型版本是 **3**：`Projection::MODEL_VERSION === Upcast::CURRENT_VERSION === 3`。

## 对外契约（10 条）

1. `Upcast::register(int $from, callable $fn): void` —— 注册 `v$from → v($from + 1)` 的升版器；
   升版器签名为 `fn(array $payload): array`，只改 payload，不动 eventId / type。
   同一个 `$from` 重复注册 → `ProjectionError`，`kind = upcast_duplicate`。

2. `Upcast::apply(Event $e): Event` —— 从事件自带版本**逐级**升到当前版本
   （`Upcast::CURRENT_VERSION`），每级**恰好应用一次**、且按版本递增的顺序应用；
   缺失中间某一级 → `ProjectionError`，`kind = upcast_gap`。

3. 事件自带版本**高于**当前版本 → `ProjectionError`，`kind = upcast_ahead`（不得静默跳过）；
   恰好等于当前版本时原样返回，一级都不跑。

4. 重放必须**幂等**：同一 `eventId` 的事件重复投递不得重复计入投影。
   去重集合必须**随快照一起持久化** —— 否则 `restore` 之后续放会把快照前的事件再算一遍。

5. `Projection::snapshot(): string` 返回可序列化状态；`Projection::restore(string $blob)` 之后
   继续重放剩余事件，结果必须与「从零全量重放同一事件流」**逐字段相同**。

6. 未注册的事件类型按构造时给的策略处理：
   - `Projection::IGNORE`：跳过，并把 eventId 记进 `skipped()`；
   - `Projection::FAIL`：抛 `ProjectionError`，`kind = unknown_type`；
   - `Projection::DEFER`（默认）：把事件缓存进 `deferred()`，之后注册该类型再 `flush()`，
     **必须保持原投递顺序**。

7. `Projection::state()` 必须是**规范形式**：所有键按字节序升序（嵌套数组同样处理），
   同语义状态序列化后逐字节相同。

8. 边界：空事件流、只有快照没有事件、同一 `eventId` 在不同版本下出现、
   `flush()` 时仍有未注册类型（`deferred()` 保持原样、顺序不变）、
   `restore` 到不兼容 blob（版本标记不匹配）→ `ProjectionError`，`kind = snapshot_version`；
   任何一种都不得抛原生 `Warning` / `TypeError`。

9. 结果不得依赖 PHP 数组的 hash 顺序；`skipped()` / `deferred()` 也必须有序
   （保持投递顺序，不是字典序）。

10. 全确定性：不用时间、随机、`spl_object_id`。

## 本次要补的一层

`src/Projection.php`、`src/Event.php`、`src/ProjectionError.php` 是**完整实现，不许改**；
起点能力是「单版本重放 + 最小快照往返」。要补的是下面两个文件里的空壳：

### `src/Upcast.php`

`Projection` 构造时可以注入一个 `Upcast`（不注入就按事件自带版本直接投递）。
注入之后，`Projection::apply()` 会先调 `Upcast::apply()` 把事件升到当前版本，再按类型解算。
要落地契约 1~3：逐级升版、`upcast_duplicate` / `upcast_gap` / `upcast_ahead` 三种报错。

### `src/Snapshot.php`

`Projection` 构造时可以注入一个 `Snapshot`。不注入时 `snapshot()` 走最小往返 —— **只存状态，
不带去重集合、不带版本标记**，够单版本重放用；注入之后由 `Snapshot` 负责把
「去重集合 + 版本标记」一起写进 blob，`restore()` 再把它们还原回去。要落地契约 4~5、8：

```php
// 写：$seen 是已按字节序升序排好的 eventId 列表
Snapshot::pack(array $state, array $seen, int $modelVersion): string

// 读：blob 不是本库写出的格式 → ProjectionError(kind = snapshot_blob)
Snapshot::unpack(string $blob): array
//   → ['modelVersion' => int, 'state' => array, 'seen' => list<string>]
```

版本标记与当前模型版本是否匹配由 `Projection::restore()` 判定（`kind = snapshot_version`），
`unpack()` 只负责如实还原。

### 判据怎么算

`check/check.php` 逐场景打印 `PASS <组>/<名>` 或 `FAIL <组>/<名>  期望=… 实际=…`，
结尾 `结果：通过 x/N`；全过退 0，否则退 1，失败不早退。
`php check/check.php -list` 列场景，`--only <组>` 只跑一组。
固定件的判据只看对外行为，不看内部结构；除了契约里写死的 `kind`，不检查任何内部字段。

约束：不改 `check/`、不改 `tests/run.php`、不改上面那三个完整实现文件；
不引入第三方依赖、不用 composer、不用 mbstring；不用 `time()` / `rand()` / `spl_object_id()`；
结果必须与 PHP 数组的 hash 顺序无关。
