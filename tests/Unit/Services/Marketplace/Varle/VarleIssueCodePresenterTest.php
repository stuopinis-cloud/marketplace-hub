<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Services\Marketplace\Varle\VarleIssueCodePresenter;
use Tests\TestCase;

class VarleIssueCodePresenterTest extends TestCase
{
    public function test_known_issue_code_labels_and_colors(): void
    {
        $this->assertSame('Missing barcode', VarleIssueCodePresenter::label('missing_barcode'));
        $this->assertSame('danger', VarleIssueCodePresenter::color('missing_barcode'));
        $this->assertSame('Supplier stock stale', VarleIssueCodePresenter::label('supplier_stock_stale'));
        $this->assertSame('warning', VarleIssueCodePresenter::color('supplier_stock_stale'));
    }

    public function test_unknown_issue_code_is_title_cased(): void
    {
        $this->assertSame('Custom Issue Code', VarleIssueCodePresenter::label('custom_issue_code'));
        $this->assertSame('gray', VarleIssueCodePresenter::color('custom_issue_code'));
    }

    public function test_issue_count_color_thresholds(): void
    {
        $this->assertSame('success', VarleIssueCodePresenter::issueCountColor(0));
        $this->assertSame('warning', VarleIssueCodePresenter::issueCountColor(2));
        $this->assertSame('danger', VarleIssueCodePresenter::issueCountColor(3));
    }
}
