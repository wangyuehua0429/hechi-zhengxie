# 内容/资源 REST API

接口实现落在 `backend/`：入口 [../backend/public/index.php](../backend/public/index.php)、路由 [../backend/routes/api.php](../backend/routes/api.php)、控制器 `backend/src/Api/`。

本目录为架构预留位：等编校微服务、县区子站或第三方系统接入时，若接口需要独立部署（独立域名、独立进程），再把 `backend/src/Api` 与 `src/Http` 作为共享包拆到这里，形成独立服务；在此之前不重复维护一份接口代码。

契约：[../docs/api-contract.md](../docs/api-contract.md)。
