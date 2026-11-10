package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

func main() {
	baseURL := envOr("BASE_URL", "http://localhost:8000")
	token := envOr("TOKEN", "anna-token")
	roomID, _ := strconv.Atoi(envOr("ROOM_ID", "1"))
	n, _ := strconv.Atoi(envOr("N", "20"))
	startsAt := envOr("STARTS_AT", "2026-12-15T14:00:00+03:00")
	endsAt := envOr("ENDS_AT", "2026-12-15T15:00:00+03:00")

	payload, err := json.Marshal(map[string]any{
		"room_id":   roomID,
		"title":     "race-go",
		"starts_at": startsAt,
		"ends_at":   endsAt,
	})
	if err != nil {
		panic(err)
	}

	var (
		wg       sync.WaitGroup
		okCount  atomic.Int64
		errCount atomic.Int64
		other    atomic.Int64
		startGate = make(chan struct{})
	)

	client := &http.Client{Timeout: 15 * time.Second}

	wg.Add(n)
	for i := 0; i < n; i++ {
		go func(i int) {
			defer wg.Done()
			<-startGate // все горутины стартуют почти одновременно

			req, err := http.NewRequest(http.MethodPost, baseURL+"/api/bookings", bytes.NewReader(payload))
			if err != nil {
				fmt.Printf("#%d build err: %v\n", i, err)
				other.Add(1)
				return
			}
			req.Header.Set("Authorization", "Bearer "+token)
			req.Header.Set("Accept", "application/json")
			req.Header.Set("Content-Type", "application/json")

			resp, err := client.Do(req)
			if err != nil {
				fmt.Printf("#%d transport err: %v\n", i, err)
				other.Add(1)
				return
			}
			body, _ := io.ReadAll(resp.Body)
			resp.Body.Close()

			switch resp.StatusCode {
			case http.StatusCreated, http.StatusOK:
				okCount.Add(1)
				fmt.Printf("#%d OK %d %s\n", i, resp.StatusCode, trim(body, 120))
			case http.StatusUnprocessableEntity:
				errCount.Add(1)
				fmt.Printf("#%d 422 %s\n", i, trim(body, 160))
			default:
				other.Add(1)
				fmt.Printf("#%d unexpected %d %s\n", i, resp.StatusCode, trim(body, 160))
			}
		}(i)
	}

	// отпускаем всех разом
	close(startGate)
	wg.Wait()

	fmt.Printf("\n=== summary ===\nN=%d created=%d rejected_422=%d other=%d\n",
		n, okCount.Load(), errCount.Load(), other.Load())

	if okCount.Load() != 1 {
		fmt.Printf("FAIL: expected exactly 1 created booking, got %d\n", okCount.Load())
		os.Exit(1)
	}
	fmt.Println("PASS: race cut to a single booking")
}

func envOr(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}

func trim(b []byte, n int) string {
	s := string(b)
	if len(s) > n {
		return s[:n] + "…"
	}
	return s
}
