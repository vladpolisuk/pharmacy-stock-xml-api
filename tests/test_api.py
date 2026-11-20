"""HTTP checks against a running API with the three demo database records."""

import os
import unittest
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET


BASE_URL = os.environ.get("PHARMACY_API_URL", "http://127.0.0.1").rstrip("/")


def request(params=None, method="GET", raw_query=None):
    query = raw_query if raw_query is not None else urllib.parse.urlencode(params or {})
    url = BASE_URL + "/api/stock" + ("?" + query if query else "")
    try:
        response = urllib.request.urlopen(urllib.request.Request(url, method=method), timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


class StockApiTests(unittest.TestCase):
    def records(self, params=None):
        status, headers, body = request(params)
        self.assertEqual(status, 200, body)
        self.assertIn("application/xml", headers["Content-Type"])
        root = ET.fromstring(body)
        self.assertEqual(root.tag, "stock_balances")
        return [{field.tag: field.text for field in row} for row in root]

    def test_original_response(self):
        rows = self.records()
        self.assertEqual(len(rows), 3)
        self.assertEqual(set(rows[0]), {
            "balance_id", "drug_name", "batch_number", "expiry_date", "quantity", "storage_temperature"
        })
        self.assertEqual(rows[0]["batch_number"], "00021-A")

    def test_each_filter_and_ranges(self):
        examples = [
            ({"balance_id": "1"}, ["1"]),
            ({"balance_id": "01"}, ["1"]),
            ({"balance_id": "4294967295"}, []),
            ({"drug_name": "УЧЕБНЫЙ ПРЕПАРАТ"}, ["1", "2"]),
            ({"batch_number": "00022-B"}, ["2"]),
            ({"storage_temperature": "+2…+8 °C"}, ["1", "3"]),
            ({"min_quantity": "1"}, ["1", "2"]),
            ({"min_quantity": "0", "max_quantity": "0"}, ["3"]),
            ({"min_quantity": "40", "max_quantity": "50"}, ["2"]),
            ({"expiry_before": "2027-12-31"}, ["1", "3"]),
            ({"expiry_after": "2028-02-29"}, ["2"]),
            ({"expiry_after": "2027-12-31", "expiry_before": "2027-12-31"}, ["1"]),
            ({"drug_name": "препарат", "min_quantity": "50", "expiry_before": "2027-12-31"}, ["1"]),
            ({"balance_id": "1", "min_quantity": "121"}, []),
            ({"drug_name": ""}, ["1", "2", "3"]),
        ]
        for params, expected in examples:
            with self.subTest(params=params):
                self.assertEqual([row["balance_id"] for row in self.records(params)], expected)

    def test_input_is_literal_data(self):
        for text in ["%", "_", "!", "' OR 1=1 --", "<>&"]:
            with self.subTest(text=text):
                self.assertEqual(self.records({"drug_name": text}), [])
        self.assertEqual(self.records({"batch_number": "00021-A' OR '1'='1"}), [])

    def test_bad_parameters_return_xml_400(self):
        invalid_queries = [
            "balance_id=0", "balance_id=-1", "balance_id=1.5", "balance_id=1e0",
            "balance_id=4294967296", "balance_id[]=1", "drug_name[]=text", "download[]=1",
            "min_quantity=-1", "min_quantity=10&max_quantity=5",
            "expiry_before=2027-02-29", "expiry_before=2028-02-30", "expiry_before=2027-1-1",
            "expiry_before=0000-01-01", "expiry_before=2027-01-01%00",
            "expiry_after=2028-01-01&expiry_before=2027-01-01",
            "download=yes", "unknown=1", "drug_name=%FF", "drug_name=%00", "%FF=x",
            urllib.parse.urlencode({"drug_name": "я" * 201}),
        ]
        for query in invalid_queries:
            with self.subTest(query=query):
                status, headers, body = request(raw_query=query)
                self.assertEqual(status, 400, body)
                self.assertIn("application/xml", headers["Content-Type"])
                self.assertEqual(ET.fromstring(body).findtext("code"), "invalid_parameter")

    def test_download_keeps_filters(self):
        status, headers, body = request({"balance_id": "2", "download": "1"})
        self.assertEqual(status, 200)
        self.assertIn('filename="medicine-stock.xml"', headers["Content-Disposition"])
        self.assertEqual([row.findtext("balance_id") for row in ET.fromstring(body)], ["2"])

    def test_head_and_post(self):
        for params, expected in [({"balance_id": "1"}, 200), ({"balance_id": "bad"}, 400)]:
            status, headers, body = request(params, method="HEAD")
            self.assertEqual(status, expected)
            self.assertEqual(body, b"")
        status, headers, body = request(method="POST")
        self.assertEqual(status, 405)
        self.assertEqual(headers["Allow"], "GET, HEAD")
        self.assertEqual(ET.fromstring(body).findtext("code"), "method_not_allowed")


if __name__ == "__main__":
    unittest.main(verbosity=2)
