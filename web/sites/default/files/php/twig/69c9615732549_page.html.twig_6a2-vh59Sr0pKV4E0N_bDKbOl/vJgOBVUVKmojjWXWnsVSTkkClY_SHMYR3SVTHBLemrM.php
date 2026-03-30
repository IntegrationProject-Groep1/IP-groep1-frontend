<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;

/* themes/custom/event_theme/templates/layout/page.html.twig */
class __TwigTemplate_ae040fb19a6a1d9638affdfd18c904b8 extends Template
{
    private Source $source;
    /**
     * @var array<string, Template>
     */
    private array $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
        $this->sandbox = $this->extensions[SandboxExtension::class];
        $this->checkSecurity();
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 1
        yield "<!DOCTYPE html>
<html";
        // line 2
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["html_attributes"] ?? null), "html", null, true);
        yield ">
<head>
  <meta charset=\"utf-8\">
  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
  <title>";
        // line 6
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["head_title"] ?? null), "html", null, true);
        yield "</title>
  ";
        // line 7
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["head"] ?? null), "html", null, true);
        yield "
  ";
        // line 8
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["styles"] ?? null), "html", null, true);
        yield "
  <link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">
  <link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>
  <link href=\"https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap\" rel=\"stylesheet\">
</head>
<body";
        // line 13
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["attributes"] ?? null), "addClass", ["layout-no-sidebars"], "method", false, false, true, 13), "html", null, true);
        yield ">
  ";
        // line 14
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["page_top"] ?? null), "html", null, true);
        yield "

  <header class=\"site-header\">
    <div class=\"layout-container site-header__inner\">
      <a href=\"";
        // line 18
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($this->extensions['Drupal\Core\Template\TwigExtension']->getPath("<front>"));
        yield "\" class=\"site-header__logo\">
        🎓 Desideriushogeschool
      </a>
      <nav class=\"site-nav\" aria-label=\"Main navigation\">
        <a href=\"";
        // line 22
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($this->extensions['Drupal\Core\Template\TwigExtension']->getPath("<front>"));
        yield "\"
          ";
        // line 23
        if ((($tmp = ($context["is_front"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            yield "class=\"is-active\"";
        }
        yield ">
          Home
        </a>
        <a href=\"";
        // line 26
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($this->extensions['Drupal\Core\Template\TwigExtension']->getPath("registration_form.register"));
        yield "\"
          ";
        // line 27
        if ((($context["current_path"] ?? null) == "/register")) {
            yield "class=\"is-active\"";
        }
        yield ">
          Register
        </a>
      </nav>
    </div>
  </header>

  <main role=\"main\">
    <div class=\"layout-container\">
      ";
        // line 36
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "content", [], "any", false, false, true, 36), "html", null, true);
        yield "
    </div>
  </main>

  <footer class=\"site-footer\">
    <div class=\"layout-container\">
      &copy; ";
        // line 42
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Twig\Extension\CoreExtension']->formatDate("now", "Y"), "html", null, true);
        yield " Desideriushogeschool Event Platform
    </div>
  </footer>

  ";
        // line 46
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["page_bottom"] ?? null), "html", null, true);
        yield "
  ";
        // line 47
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["scripts"] ?? null), "html", null, true);
        yield "
</body>
</html>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["html_attributes", "head_title", "head", "styles", "attributes", "page_top", "is_front", "current_path", "page", "page_bottom", "scripts"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "themes/custom/event_theme/templates/layout/page.html.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable(): bool
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo(): array
    {
        return array (  138 => 47,  134 => 46,  127 => 42,  118 => 36,  104 => 27,  100 => 26,  92 => 23,  88 => 22,  81 => 18,  74 => 14,  70 => 13,  62 => 8,  58 => 7,  54 => 6,  47 => 2,  44 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "themes/custom/event_theme/templates/layout/page.html.twig", "/opt/drupal/web/themes/custom/event_theme/templates/layout/page.html.twig");
    }
    
    public function checkSecurity()
    {
        static $tags = ["if" => 23];
        static $filters = ["escape" => 2, "date" => 42];
        static $functions = ["path" => 18];

        try {
            $this->sandbox->checkSecurity(
                ['if'],
                ['escape', 'date'],
                ['path'],
                $this->source
            );
        } catch (SecurityError $e) {
            $e->setSourceContext($this->source);

            if ($e instanceof SecurityNotAllowedTagError && isset($tags[$e->getTagName()])) {
                $e->setTemplateLine($tags[$e->getTagName()]);
            } elseif ($e instanceof SecurityNotAllowedFilterError && isset($filters[$e->getFilterName()])) {
                $e->setTemplateLine($filters[$e->getFilterName()]);
            } elseif ($e instanceof SecurityNotAllowedFunctionError && isset($functions[$e->getFunctionName()])) {
                $e->setTemplateLine($functions[$e->getFunctionName()]);
            }

            throw $e;
        }

    }
}
