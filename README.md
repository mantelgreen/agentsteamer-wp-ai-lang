# agentsteamer-wp-ai-lang

> 自包含的 WordPress 多语言插件：语言切换、浏览器语言自动跳转、多语言语言稿关联，以及通过大模型接口自动生成多语言译本。

模釜官网：<https://www.agentsteamer.com> 官方 Blog：<https://blog.agentsteamer.com>

一个 **WordPress 原生、自包含** 的多语言插件。每个语言版本都是一篇真实的文章/页面，通过「翻译组」互相关联，因此每种语言都有独立的 URL、SEO 元数据与 sitemap 条目。不依赖任何外部平台，大模型接口由站点自行配置。

> 说明：本项目**源于模釜官网自用需求**，但按**通用插件**设计：配置自包含、可白标、可迁移，可安装到任意 WordPress 站点。

## 功能

### 语言管理
- 增删改、排序语言（代码、locale、URL 段、文本方向、国旗）
- 唯一默认语言；默认语言可选是否隐藏 URL 前缀
- URL 形态：目录（`example.com/en/`）或查询参数（`?asl_lang=en`）

### 语言稿关联
- 为文章/页面指定语言
- 将同一内容的多语言版本关联为翻译组
- 手工「复制并关联」生成语言稿（默认草稿）
- 列表页语言列与语言筛选

### 自动切换
- 按浏览器语言首次访问自动跳转（可开关）
- Cookie 记忆用户选择，避免反复跳转
- `?no_redirect=1` 免跳转；防重定向死循环

### AI 翻译
- 配置 OpenAI 兼容 / Anthropic Claude / Google Gemini / 自定义接口
- 将标题、正文、摘要、SEO 字段翻译为关联语言稿
- 术语表（固定译法 / 不翻译词）
- 审阅队列：应用 / 拒绝 / 回滚

### 多语言 SEO
- `hreflang` + `x-default`、`<html lang>` / `dir`、`og:locale`
- 每语言独立 URL 与 canonical

### 开发者接口
- 模板标签 `agentsteamer_lang_switcher()`，短代码 `[agentsteamer_lang_switcher]`
- WP-CLI：`wp agentsteamer-lang lang-list` / `lang-add` / `lang-delete`
- REST：`agentsteamer-lang/v1`
- Hooks：`agentsteamer_lang_*`

## 与 SEO 插件配合

当同时启用 AgentSteamer SEO/GEO 插件（`agentsteamer-wp-ai-geo`）时，本插件会自动桥接：分语言 sitemap 条目与收录提交，同时 `hreflang` 由本插件统一输出以避免重复。

## 环境要求

| 项 | 要求 |
| --- | --- |
| WordPress | ≥ 5.8 |
| PHP | ≥ 7.4 |
| 其它 | 可选：一个大模型接口（用于 AI 翻译） |

## 安装

1. 将 `agentsteamer-wp-ai-lang` 目录放入 `/wp-content/plugins/`。
2. 在「插件」页面启用。
3. 或将发布的zip包直接上传至插件
4. 前往 **AgentSteamer Lang → 语言** 添加语言并设置默认语言。
5. 前往 **AgentSteamer Lang → 设置** 配置 URL/检测与大模型接口。

## 使用

- **语言切换器**：在主题中调用 `agentsteamer_lang_switcher()`，或使用短代码 `[agentsteamer_lang_switcher]`，或添加「语言切换器」小工具。
- **文章语言**：在文章编辑页设置语言；在列表页可按语言筛选。
- **AI 翻译**：在文章编辑页将内容翻译为其它语言，生成语言稿并进入审阅队列。

## 目录结构

```text
agentsteamer-wp-ai-lang/
├─ agentsteamer-wp-ai-lang.php   插件入口：常量、加载、激活 / 卸载钩子
├─ assets/                       css · js · images
├─ inc/
│  ├─ providers/                 大模型 Provider 适配
│  ├─ class-plugin.php           模块装配
│  ├─ class-languages.php        语言注册表
│  ├─ class-translations.php     翻译组关联
│  ├─ class-router.php           URL 路由
│  ├─ class-detect.php           浏览器语言检测 / 跳转
│  ├─ class-switcher.php         语言切换器
│  ├─ class-meta.php             hreflang / html lang / og:locale
│  └─ ...                        其余模块
├─ languages/                    插件自身翻译
├─ readme.txt                    WordPress.org 格式说明
└─ uninstall.php                 卸载清理
```

## 开发

- 源码即插件目录，模块化结构（见 `inc/`）。
- PHP 兼容基线为 **7.4**，未使用 PHP 8 专有语法。
- 开发时可将本目录同步/软链到本地 WordPress 的 `wp-content/plugins/` 下进行调试。

## 设计原则

- **自包含**：不依赖外部平台；大模型接口由站点管理员自行配置。
- **人机协同**：AI 只生成「语言稿草案」，经审阅后生效，可回滚。
- **原生优先**：使用 WordPress 原生文章与分类法，保证兼容与性能。
- **零遥测**。

## 许可证

GPLv2 或更高版本，见 [LICENSE](LICENSE)。
