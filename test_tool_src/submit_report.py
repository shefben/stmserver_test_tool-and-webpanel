#!/usr/bin/env python3
"""
Steam Emulator Test Report Submission Script

Usage:
    python submit_report.py --url URL --api-key KEY --file FILE

Example:
    python submit_report.py \
        --url http://localhost/test_api/api/submit.php \
        --api-key sk_test_abc123... \
        --file session_results.json
"""

import argparse
import json
import sys
import os

try:
    import requests
except ImportError:
    print("Error: 'requests' library is required. Install with: pip install requests")
    sys.exit(1)


def submit_report(url: str, api_key: str, file_path: str, verbose: bool = False) -> bool:
    """
    Submit a test report to the API.

    Args:
        url: API endpoint URL
        api_key: API key for authentication
        file_path: Path to session_results.json file
        verbose: Print detailed output

    Returns:
        True if submission was successful, False otherwise
    """
    # Validate file exists
    if not os.path.exists(file_path):
        print(f"Error: File not found: {file_path}")
        return False

    # Read and parse JSON file
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
    except json.JSONDecodeError as e:
        print(f"Error: Invalid JSON in file: {e}")
        return False
    except IOError as e:
        print(f"Error: Could not read file: {e}")
        return False

    if verbose:
        print(f"Loaded report file: {file_path}")
        if 'metadata' in data:
            print(f"  Tester: {data['metadata'].get('tester_name', 'Unknown')}")
            print(f"  Test Type: {data['metadata'].get('test_type', 'Unknown')}")
        if 'tests' in data:
            versions = list(data['tests'].keys())
            print(f"  Versions: {len(versions)}")
            for v in versions[:3]:
                print(f"    - {v}")
            if len(versions) > 3:
                print(f"    ... and {len(versions) - 3} more")

    # Prepare headers
    headers = {
        'Content-Type': 'application/json',
        'X-API-Key': api_key
    }

    # Submit to API
    try:
        if verbose:
            print(f"\nSubmitting to: {url}")

        response = requests.post(url, json=data, headers=headers, timeout=30)

        # Parse response
        try:
            result = response.json()
        except json.JSONDecodeError:
            result = {'raw_response': response.text}

        if response.status_code == 201:
            print("\n✓ Report submitted successfully!")
            print(f"  Report ID: {result.get('report_id', 'N/A')}")
            print(f"  Client Version: {result.get('client_version', 'N/A')}")
            print(f"  Tests Recorded: {result.get('tests_recorded', 'N/A')}")
            if 'view_url' in result:
                print(f"  View URL: {result['view_url']}")
            return True
        else:
            print(f"\n✗ Submission failed (HTTP {response.status_code})")
            if 'error' in result:
                print(f"  Error: {result['error']}")
            if verbose and 'expected' in result:
                print(f"  Expected format: {json.dumps(result['expected'], indent=2)}")
            return False

    except requests.exceptions.ConnectionError:
        print(f"Error: Could not connect to {url}")
        return False
    except requests.exceptions.Timeout:
        print("Error: Request timed out")
        return False
    except requests.exceptions.RequestException as e:
        print(f"Error: Request failed: {e}")
        return False


def main():
    parser = argparse.ArgumentParser(
        description='Submit Steam emulator test reports to the panel API',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  %(prog)s --url http://localhost/test_api/api/submit.php --api-key sk_test_abc123 --file session_results.json
  %(prog)s -u http://example.com/api/submit.php -k YOUR_KEY -f report.json -v
        """
    )

    parser.add_argument(
        '-u', '--url',
        required=True,
        help='API endpoint URL (e.g., http://localhost/test_api/api/submit.php)'
    )

    parser.add_argument(
        '-k', '--api-key',
        required=True,
        help='API key for authentication'
    )

    parser.add_argument(
        '-f', '--file',
        required=True,
        help='Path to the session_results.json file'
    )

    parser.add_argument(
        '-v', '--verbose',
        action='store_true',
        help='Print detailed output'
    )

    args = parser.parse_args()

    success = submit_report(args.url, args.api_key, args.file, args.verbose)
    sys.exit(0 if success else 1)


if __name__ == '__main__':
    main()
