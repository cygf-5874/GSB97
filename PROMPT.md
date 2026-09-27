投影现在只认一个版本的事件，回放旧数据全靠硬改文件。evolveproj 是 PHP 8 的
事件溯源投影库（php-cli，无 composer，无第三方依赖），自检走 `scripts/check.sh`
（`check/` 是固定验收程序，别改）。

单版本重放与快照往返这一层已经能用：`src/Projection.php`、`src/Event.php`、
`src/ProjectionError.php` 都是完整实现，`tests/run.php` 的 14 个用例全绿。
`src/Upcast.php` 与 `src/Snapshot.php` 里都还是空壳。

任务：按 README「对外契约」的 10 条把升版与快照这一层补出来，让既有用例与固定件全过。

验收：
- php tests/run.php 退出码 0（既有用例仍全绿）；
- php check/check.php 退出码 0，11 个场景全过（upcast 3 + snapshot 3 + defer 2 + state 2 + robust 1）。

约束：
1. 不改 `check/`、不改 `tests/run.php`、不改 `Projection.php` / `Event.php` / `ProjectionError.php`。
2. 不引入第三方依赖，不用 composer，不用 mbstring，只用 PHP 标准库。
3. 不许用 `time()`、`rand()`、`spl_object_id()` 参与结果。
4. 结果必须与 PHP 数组的 hash 顺序无关。
