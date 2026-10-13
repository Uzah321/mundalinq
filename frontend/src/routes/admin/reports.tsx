import { useState } from "react";
import { createFileRoute, Link } from "@tanstack/react-router";
import { FileSpreadsheet, LoaderCircle } from "lucide-react";
import { toast } from "sonner";
import { ItemState, PageHeading, Section } from "@/components/admin/ui";
import { Button } from "@/components/ui/button";
import { useAdminItem } from "@/lib/admin";
import { downloadFile, errorMessage } from "@/lib/api";
import { formatMoney, fromMinor, toMinor } from "@/lib/format";
import { cn } from "@/lib/utils";

type MonthRow = {
  month: string;
  orders_placed: number;
  orders_completed: number;
  sales: string;
  platform_fees: string;
  featuring: string;
  income: string;
  refunds: string;
  payouts: string;
};

type FarmRow = { farm_id: string; farm: string; sales: string; platform_fees: string; refunds: string; featuring: string; payouts: string; net: string };

const now = new Date();
const thisYear = now.getFullYear();
const thisMonth = `${thisYear}-${String(now.getMonth() + 1).padStart(2, "0")}`;
const MONEY: readonly (keyof MonthRow)[] = ["sales", "platform_fees", "featuring", "income", "refunds", "payouts"];

export const Route = createFileRoute("/admin/reports")({
  validateSearch: (search: Record<string, unknown>): { year?: number; month?: string } => ({
    year: Number(search.year) >= 2020 && Number(search.year) <= thisYear ? Number(search.year) : undefined,
    month: typeof search.month === "string" && /^\d{4}-\d{2}$/.test(search.month) ? search.month : undefined,
  }),
  component: Reports,
});

const monthName = (month: string) => new Date(`${month}-01T00:00:00`).toLocaleDateString("en-GB", { month: "long", year: "numeric" });

/** Saves a report the API sends as a CSV file, which Excel opens directly. */
async function download(path: string, filename: string) {
  try {
    await downloadFile(path, filename);
  } catch (error) {
    toast.error(errorMessage(error));
  }
}

function Reports() {
  const { year = thisYear, month = thisMonth } = Route.useSearch();
  const navigate = Route.useNavigate();
  const monthly = useAdminItem<MonthRow[]>("/admin/reports/monthly", { year });
  const farms = useAdminItem<FarmRow[]>("/admin/reports/farms", { month });
  const [busy, setBusy] = useState<string | null>(null);
  const money = (amount: string) => formatMoney(amount);

  async function save(kind: "monthly" | "farms") {
    setBusy(kind);
    await (kind === "monthly"
      ? download(`/admin/reports/monthly?year=${year}&format=csv`, `mundalinq-monthly-${year}.csv`)
      : download(`/admin/reports/farms?month=${month}&format=csv`, `mundalinq-farms-${month}.csv`));
    setBusy(null);
  }

  const rows = monthly.data ?? [];
  const total = (key: keyof MonthRow) =>
    MONEY.includes(key) ? fromMinor(rows.reduce((sum, row) => sum + (toMinor(String(row[key])) ?? 0), 0)) : String(rows.reduce((sum, row) => sum + Number(row[key]), 0));

  return (
    <>
      <PageHeading title="Reports" text="Money by month for your accounts: sales, MundaLinq's income from fees and featuring, refunds and payouts. Months follow Zimbabwe time." />

      <div className="admin-filters">
        <select className="admin-select" value={year} onChange={(event) => navigate({ search: (current) => ({ ...current, year: Number(event.target.value) }) })} aria-label="Year">
          {[thisYear, thisYear - 1, thisYear - 2].map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
        <Button variant="outline" onClick={() => save("monthly")} disabled={busy !== null}>
          {busy === "monthly" ? <LoaderCircle className="animate-spin" /> : <FileSpreadsheet />} Download {year} for Excel
        </Button>
      </div>

      <Section title={`${year}, month by month`} wide>
        {!monthly.data ? (
          <ItemState query={monthly} missing="The report isn't available" />
        ) : (
          <div className="report-scroll">
            <table className="admin-table report-table">
              <thead>
                <tr>
                  <th>Month</th>
                  <th>Orders</th>
                  <th>Completed</th>
                  <th>Sales</th>
                  <th>Platform fees</th>
                  <th>Featuring</th>
                  <th>Income</th>
                  <th>Refunds</th>
                  <th>Payouts</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr
                    key={row.month}
                    className={cn("report-row", row.month === month && "selected")}
                    onClick={() => navigate({ search: (current) => ({ ...current, month: row.month }) })}
                    title="See each farm for this month"
                  >
                    <td>{monthName(row.month).split(" ")[0]}</td>
                    <td>{row.orders_placed}</td>
                    <td>{row.orders_completed}</td>
                    {MONEY.map((key) => (
                      <td key={key} className={key === "income" ? "report-income" : undefined}>
                        {money(String(row[key]))}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr>
                  <td>Total</td>
                  <td>{total("orders_placed")}</td>
                  <td>{total("orders_completed")}</td>
                  {MONEY.map((key) => (
                    <td key={key} className={key === "income" ? "report-income" : undefined}>
                      {money(total(key))}
                    </td>
                  ))}
                </tr>
              </tfoot>
            </table>
          </div>
        )}
        <p className="admin-hint">Income is platform fees plus featuring. Tap a month to see each farm.</p>
      </Section>

      <Section
        title={`Farms in ${monthName(month)}`}
        wide
        actions={
          <Button variant="outline" size="sm" onClick={() => save("farms")} disabled={busy !== null}>
            {busy === "farms" ? <LoaderCircle className="animate-spin" /> : <FileSpreadsheet />} Download for Excel
          </Button>
        }
      >
        {!farms.data ? (
          <ItemState query={farms} missing="The report isn't available" />
        ) : farms.data.length === 0 ? (
          <p className="admin-hint">No sales, fees or payouts this month.</p>
        ) : (
          <div className="report-scroll">
            <table className="admin-table report-table">
              <thead>
                <tr>
                  <th>Farm</th>
                  <th>Sales</th>
                  <th>Fees</th>
                  <th>Refunds</th>
                  <th>Featuring</th>
                  <th>Payouts</th>
                  <th>Earned</th>
                </tr>
              </thead>
              <tbody>
                {farms.data.map((row) => (
                  <tr key={row.farm_id}>
                    <td>
                      <Link to="/admin/farms" search={{ q: row.farm }} className="admin-link">
                        {row.farm}
                      </Link>
                    </td>
                    <td>{money(row.sales)}</td>
                    <td>{money(row.platform_fees)}</td>
                    <td>{money(row.refunds)}</td>
                    <td>{money(row.featuring)}</td>
                    <td>{money(row.payouts)}</td>
                    <td className="report-income">{money(row.net)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <p className="admin-hint">Earned is sales less platform fees, refunds and featuring: what the farm is owed for the month before payouts.</p>
      </Section>
    </>
  );
}
