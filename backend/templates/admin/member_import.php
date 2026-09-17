<?php

/**
 * 委员名册导入：模板下载、上传、导入报告与一次性密码清单下载。
 *
 * @var list<string> $headers
 * @var array<string, mixed>|null $result
 * @var list<array<string, mixed>> $credentials
 * @var bool $hasCredentials
 * @var string $csrf
 */

declare(strict_types=1);
?>
<div class="page-head">
  <h1>委员名册导入</h1>
  <a class="btn btn-sm" href="/admin/members">返回委员管理</a>
</div>

<div class="card">
  <h2>1. 下载并填写模板</h2>
  <p class="muted">
    模板是 CSV 文件，用 Excel 或 WPS 双击打开即可编辑。模板表头为：
    <?php foreach ($headers as $index => $header): ?><?= $index > 0 ? '、' : '' ?><code><?= hechi_e($header) ?></code><?php endforeach; ?>。
    「姓名」与「职务」必填——缺这两列会拒收整份文件，某一行留空则该行不入库并列出原因；
    登录名取委员本人姓名，重名的自动补序号（<code>张三</code>、<code>张三2</code>）。
    「界别」与「联系电话」可以留空，留空联系电话的委员只能用姓名登录；同一名册内联系电话不得重复。
    表头与姓名里的空格会自动去掉，所以「姓 名」「现 任 职 务」「韦　　平」这类排版空格不影响导入。
  </p>
  <p><a class="btn" href="/admin/members/import/template.csv">下载导入模板</a></p>
</div>

<div class="card">
  <h2>2. 上传并开通账号</h2>
  <p class="muted">
    导入后系统为每人生成随机初始密码，委员首次登录须自行修改。
    为安全起见，初始密码不写入数据库，只在导入完成后显示一次，请当场下载清单。
  </p>
  <form method="post" action="/admin/members/import" enctype="multipart/form-data" class="filters">
    <?= $csrf ?>
    <label>名册文件
      <input type="file" name="roster" accept=".csv,text/csv" required>
    </label>
    <button type="submit" class="btn-primary">开始导入</button>
  </form>
</div>

<?php if ($result !== null): ?>
  <div class="card">
    <h2>3. 导入结果</h2>
    <p>
      共处理 <?= (int) ($result['total'] ?? 0) ?> 行；
      成功 <strong><?= (int) ($result['created_count'] ?? 0) ?></strong> 行、
      失败 <strong><?= count((array) ($result['failed'] ?? [])) ?></strong> 行。
    </p>
    <?php if ($hasCredentials): ?>
      <p><a class="btn-primary" href="/admin/members/credentials.csv">下载初始密码清单</a></p>
      <p class="muted">
        初始密码只在本次导入后下载一次：点上面的按钮存下清单，下载后本页不再显示、也不能重复下载。
      </p>
      <div class="table-scroll">
        <table class="grid">
          <caption class="visually-hidden">已开通账号</caption>
          <thead><tr><th>姓名</th><th>登录名</th><th>初始密码</th></tr></thead>
          <tbody>
            <?php foreach ($credentials as $row): ?>
              <tr>
                <td class="nowrap"><?= hechi_e((string) $row['name']) ?></td>
                <td class="nowrap"><?= hechi_e((string) $row['login_name']) ?></td>
                <td class="nowrap"><code><?= hechi_e((string) $row['password']) ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ((int) ($result['created_count'] ?? 0) > 0): ?>
      <p class="muted">
        初始密码清单已下载并从页面清除。如委员没有收到，请到委员管理里用「重置密码」重新生成一份。
      </p>
    <?php endif; ?>
    <?php if ((array) ($result['failed'] ?? []) !== []): ?>
      <h2>未导入的行</h2>
      <div class="table-scroll">
        <table class="grid">
          <caption class="visually-hidden">未导入的行与原因</caption>
          <thead><tr><th>行号</th><th>姓名</th><th>原因</th></tr></thead>
          <tbody>
            <?php foreach ((array) $result['failed'] as $row): ?>
              <tr>
                <td class="nowrap">第 <?= (int) $row['line'] ?> 行</td>
                <td class="nowrap"><?= hechi_e((string) $row['name']) ?></td>
                <td><?= hechi_e((string) $row['reason']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="muted">请修正后重新上传这些行；已导入的账号不会重复开通。</p>
    <?php endif; ?>
  </div>
<?php endif; ?>
