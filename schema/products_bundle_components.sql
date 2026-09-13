--
-- products_bundle_components — what a bundle product ships as
--
-- OPTIONAL. freebooks-zencart.php probes for this table and does nothing at all
-- when it is absent, so an install that has no use for it needs no action. Create
-- it only if something on your store packs a bundle's parts separately.
--
-- Why it exists
-- -------------
-- A product sold as one line is not always shipped as one parcel. A dimensional
-- shipping module that packs by volume needs the individual pieces, not the
-- assembled whole, or it quotes for a box nothing actually travels in.
--
-- The ERP is where a bundle's composition is maintained, so it sends the pieces
-- with the product (`bundleComponents` on updateProducts) and they are stored
-- here. Rows are replaced wholesale on every push that carries the field: an
-- absent field leaves existing rows untouched, an empty array clears them.
--
-- What a row is
-- -------------
-- One piece of the shipment, already multiplied out by nothing — `quantity` says
-- how many identical pieces this row stands for. These are SHIPPING PIECES, not
-- a bill of materials: a bundle that travels as one carton plus a loose length of
-- pipe is two rows, whatever its parts list says. A bundle that ships assembled
-- has no rows at all, and is packed on the product's own weight and dimensions.
--
-- `sku` is carried for diagnostics only. It is the ERP's SKU, not passed through
-- the `sku.import` filter, and nothing joins on it — a piece need not exist in
-- the catalogue as a product of its own, and often will not.
--
-- Units match the rest of the API: dimensions in the store's own dimension unit
-- (cm unless you have changed it), weight in kg. Zero dimensions are stored as
-- sent and pack as zero volume, which is the honest answer when the ERP has no
-- figures rather than a guess dressed up as data.
--
-- Table name
-- ----------
-- Add your DB_PREFIX if you use one, exactly as with Zen Cart's own tables.
--

CREATE TABLE IF NOT EXISTS products_bundle_components (
  products_id INT(11) NOT NULL,
  sort_order  SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
  sku         VARCHAR(64) NOT NULL DEFAULT '',
  quantity    DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  length      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  width       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  height      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  weight      DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (products_id, sort_order)
);

-- To remove it again, along with everything the ERP has written into it:
--
--   DROP TABLE products_bundle_components;
--
-- Nothing else references it, and freebooks-zencart.php returns to ignoring the
-- field the moment the table is gone.
